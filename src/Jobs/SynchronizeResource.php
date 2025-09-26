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

    public function handle()
    {
        try {
            $this->ensureTenantDbLoaded();
            $service = $this->getGoogleService();

            $pageToken = null;
            $syncToken = $this->synchronization->token;

            $options = compact('pageToken', 'syncToken');

            // Fetch events/items from Google
            $list = $this->getGoogleRequest($service, $options);

            if (empty($list)) {
                Log::info('No items returned from Google, skipping synchronization', [
                    'account_id' => $this->synchronizable->id ?? null,
                    'tenant_db' => $this->tenantDb,
                ]);
                return;
            }

            // Sync each item
            foreach ($list as $item) {
                $this->syncItem($item);
            }

            // Save a new sync token if available
            if (method_exists($list, 'getNextSyncToken') && $list->getNextSyncToken()) {
                $this->synchronization->update([
                    'token' => $list->getNextSyncToken(),
                    'last_synchronized_at' => now(),
                ]);

                Log::info('Next sync token updated', [
                    'account_id' => $this->synchronizable->id ?? null,
                    'tenant_db'  => $this->tenantDb,
                    'next_sync_token' => $list->getNextSyncToken(),
                ]);
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
