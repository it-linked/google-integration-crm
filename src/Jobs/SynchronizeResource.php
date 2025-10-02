<?php

namespace Webkul\Google\Jobs;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Google_Service_Exception;
use Google_Client;

abstract class SynchronizeResource
{
    protected $synchronizable;
    protected $synchronization;
    protected ?string $tenantDb = null;
    protected bool $tenantDbLoaded = false;

    protected ?\Google_Service_Calendar $googleService = null;
    protected $lastResponse = null;

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
        }

        $this->tenantDbLoaded = true;
    }

    protected function getGoogleService(): \Google_Service_Calendar
    {
        if ($this->googleService) return $this->googleService;

        $this->ensureTenantDbLoaded();

        try {
            $googleApp = \Webkul\Google\Models\GoogleApp::first();

            $client = new Google_Client();
            $client->setClientId($googleApp->client_id);
            $client->setClientSecret($googleApp->client_secret);
            $client->setRedirectUri($googleApp->redirect_uri);
            $client->setScopes($googleApp->scopes);
            $client->setAccessType('offline');
            $client->setPrompt('consent');

            // Set token from DB
            if ($this->synchronizable->token) {
                $client->setAccessToken($this->synchronizable->token);

                if ($client->isAccessTokenExpired() && $client->getRefreshToken()) {
                    $newToken = $client->fetchAccessTokenWithRefreshToken($client->getRefreshToken());

                    if (!isset($newToken['error'])) {
                        $this->synchronizable->update([
                            'token' => $client->getAccessToken(),
                        ]);
                    }
                }
            } else {
                Log::warning("No token found for synchronizable", [
                    'id' => $this->synchronizable->id,
                    'type' => get_class($this->synchronizable)
                ]);
            }

            $this->googleService = new \Google_Service_Calendar($client);

        } catch (\Exception $e) {
            Log::error("Failed to initialize Google service: {$e->getMessage()}", [
                'account_id' => $this->synchronizable->id ?? null,
                'tenant_db'  => $this->tenantDb,
            ]);
            throw $e;
        }

        return $this->googleService;
    }

    protected function getPrimaryCalendar()
    {
        if ($this->synchronizable instanceof \Webkul\Google\Models\Account) {
            return $this->synchronizable->calendars->firstWhere('is_primary', 1);
        } elseif ($this->synchronizable instanceof \Webkul\Google\Models\Calendar) {
            return $this->synchronizable;
        }

        Log::warning("Unknown synchronizable type", [
            'type' => get_class($this->synchronizable)
        ]);

        return null;
    }

    public function handle()
    {
        try {
            $this->ensureTenantDbLoaded();

            if (!$this->synchronizable->token) {
                Log::warning("Skipping sync: missing token", [
                    'id' => $this->synchronizable->id,
                    'type' => get_class($this->synchronizable)
                ]);
                return;
            }

            $service = $this->getGoogleService();
            $items = $this->getGoogleRequest($service, [
                'pageToken' => null,
                'syncToken' => $this->synchronization->token
            ]);

            foreach ($items as $item) {
                $this->syncItem($item);
            }

            // Update next sync token safely
            if ($this->lastResponse && method_exists($this->lastResponse, 'getNextSyncToken')) {
                $nextToken = $this->lastResponse->getNextSyncToken();
                if ($nextToken) {
                    $this->synchronization->update([
                        'token' => $nextToken,
                        'last_synchronized_at' => now(),
                    ]);
                }
            }

        } catch (Google_Service_Exception $e) {
            $decoded = json_decode($e->getMessage(), true);

            if (isset($decoded['error']) && $decoded['error'] === 'invalid_grant') {
                Log::warning("Google token revoked or expired", [
                    'id' => $this->synchronizable->id,
                    'type' => get_class($this->synchronizable)
                ]);

                if (method_exists($this->synchronizable, 'update')) {
                    $this->synchronizable->update(['requires_reauth' => 1]);
                }

                return;
            }

            Log::error('SynchronizeResource API error', [
                'error' => $e->getMessage(),
                'stack' => $e->getTraceAsString(),
            ]);
        } catch (\Exception $e) {
            Log::error('SynchronizeResource job failed', [
                'error' => $e->getMessage(),
                'stack' => $e->getTraceAsString(),
            ]);
        }
    }

    abstract public function getGoogleRequest($service, $options);
    abstract public function syncItem($item);
    abstract public function dropAllSyncedItems();
}
