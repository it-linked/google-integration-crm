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
        }

        $this->tenantDbLoaded = true;
    }

    protected function getGoogleService(): \Google_Service_Calendar
    {
        if ($this->googleService) return $this->googleService;

        $this->ensureTenantDbLoaded();

        try {
            // Use service class instead of raw client
            $google = app(\Webkul\Google\Services\Google::class);

            // Let it connect with refresh logic
            $google->connectWithSynchronizable($this->synchronizable);

            $this->googleService = $google->service('Calendar');
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
                }
            }
        } catch (Google_Service_Exception $e) {
            $decoded = json_decode($e->getMessage(), true);

            if (isset($decoded['error']) && $decoded['error'] === 'invalid_grant') {
                // Token revoked/expired permanently
                Log::warning("Google token revoked or expired - reauth required", [
                    'account_id' => $this->synchronizable->id ?? null,
                    'tenant_db'  => $this->tenantDb,
                ]);

                // Mark account as requiring reauth
                if (method_exists($this->synchronizable, 'update')) {
                    $this->synchronizable->update(['requires_reauth' => 1]);
                }

                return; // don’t retry
            }

            Log::error('SynchronizeResource API error', [
                'error'      => $e->getMessage(),
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
