<?php

namespace Webkul\Google\Jobs;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

abstract class SynchronizeResource
{
    protected $synchronizable;
    protected $synchronization;
    protected ?string $tenantDb = null;
    protected bool $tenantDbLoaded = false;

    protected ?\Google_Service_Calendar $googleService = null;
    protected $lastResponse = null; // Store last Google response

    public function __construct($synchronizable, ?string $tenantDb = null)
    {
        $this->synchronizable = $synchronizable;
        $this->synchronization = $synchronizable->synchronization;
        $this->tenantDb = $tenantDb;
    }

    protected function ensureTenantDbLoaded(): void
    {
        if ($this->tenantDbLoaded) return;

        if ($this->tenantDb) {
            Config::set('database.connections.tenant.database', $this->tenantDb);
            DB::purge('tenant');
            DB::reconnect('tenant');
            Config::set('database.default', 'tenant');

            Log::info('Tenant DB switched', [
                'tenant_db' => $this->tenantDb,
                'account_id' => $this->synchronizable->id ?? null,
            ]);
        }

        $this->tenantDbLoaded = true;
    }

    protected function getGoogleService(): \Google_Service_Calendar
    {
        if ($this->googleService) return $this->googleService;

        $this->ensureTenantDbLoaded();

        try {
            $googleApp = \Webkul\Google\Models\GoogleApp::first();

            $client = new \Google_Client();
            $client->setClientId($googleApp->client_id);
            $client->setClientSecret($googleApp->client_secret);
            $client->setRedirectUri($googleApp->redirect_uri);
            $client->setScopes($googleApp->scopes);
            $client->setAccessToken($this->synchronizable->token ?? null);

            $this->googleService = new \Google_Service_Calendar($client);

            Log::info('Google service initialized', [
                'account_id' => $this->synchronizable->id ?? null,
                'tenant_db'  => $this->tenantDb,
            ]);
        } catch (\Exception $e) {
            Log::error("Failed to initialize Google service: {$e->getMessage()}", [
                'account_id' => $this->synchronizable->id ?? null,
                'tenant_db'  => $this->tenantDb,
            ]);
            throw $e;
        }

        return $this->googleService;
    }

    protected function getPrimaryCalendarId(): ?string
    {
        $primaryCalendar = $this->synchronizable->calendars()
            ->where('is_primary', 1)
            ->first();

        if ($primaryCalendar) {
            return $primaryCalendar->google_id;
        }

        return $this->synchronizable->google_id;
    }

    public function handle()
    {
        try {
            $this->ensureTenantDbLoaded();
            $service = $this->getGoogleService();

            $pageToken = null;
            $syncToken = $this->synchronization->token;

            $options = compact('pageToken', 'syncToken');

            $allItems = $this->getGoogleRequest($service, $options);

            if (empty($allItems)) {
                Log::info('No items returned from Google, skipping synchronization', [
                    'account_id' => $this->synchronizable->id ?? null,
                    'tenant_db' => $this->tenantDb,
                ]);
                return;
            }

            foreach ($allItems as $item) {
                $this->syncItem($item);
            }

            // Safely update next sync token
            if ($this->lastResponse && method_exists($this->lastResponse, 'getNextSyncToken')) {
                $nextToken = $this->lastResponse->getNextSyncToken();
                if ($nextToken) {
                    $this->synchronization->update([
                        'token' => $nextToken,
                        'last_synchronized_at' => now(),
                    ]);

                    Log::info('Next sync token updated', [
                        'account_id' => $this->synchronizable->id ?? null,
                        'tenant_db'  => $this->tenantDb,
                        'next_sync_token' => $nextToken,
                    ]);
                }
            }

            Log::info('Synchronization completed', [
                'account_id' => $this->synchronizable->id ?? null,
                'tenant_db'  => $this->tenantDb,
            ]);
        } catch (\Exception $e) {
            Log::error('SynchronizeResource job failed', [
                'error'      => $e->getMessage(),
                'stack'      => $e->getTraceAsString(),
                'account_id' => $this->synchronizable->id ?? null,
                'tenant_db'  => $this->tenantDb,
            ]);
        }
    }

    abstract public function getGoogleRequest($service, $options);
    abstract public function syncItem($item);
    abstract public function dropAllSyncedItems();
}

