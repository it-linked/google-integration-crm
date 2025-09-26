<?php

namespace Webkul\Google\Jobs;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

abstract class SynchronizeResource
{
    protected $synchronizable;
    protected $synchronization;

    // Lazy-loaded Google service
    protected ?\Google_Service_Calendar $googleService = null;

    // Tenant DB name
    protected ?string $tenantDb = null;
    protected bool $tenantDbLoaded = false;

    public function __construct($synchronizable, ?string $tenantDb = null)
    {
        $this->synchronizable = $synchronizable;
        $this->synchronization = $synchronizable->synchronization;
        $this->tenantDb = $tenantDb;
    }

    /**
     * Ensure tenant database is selected
     */
    protected function ensureTenantDbLoaded(): void
    {
        if ($this->tenantDbLoaded) return;

        if ($this->tenantDb) {
            Config::set('database.connections.tenant.database', $this->tenantDb);
            DB::purge('tenant');
            DB::reconnect('tenant');
            Config::set('database.default', 'tenant');

            Log::info('SynchronizeResource: Tenant DB switched', [
                'tenant_id' => $this->synchronizable->id ?? null,
                'tenant_db' => $this->tenantDb,
            ]);
        }

        $this->tenantDbLoaded = true;
    }

    /**
     * Lazy-load Google Calendar service
     */
    protected function getGoogleService(): \Google_Service_Calendar
    {
        if ($this->googleService) return $this->googleService;

        $this->ensureTenantDbLoaded();
        $this->googleService = $this->synchronizable->getGoogleService('Calendar');

        Log::info('SynchronizeResource: Google service initialized', [
            'account_id' => $this->synchronizable->id ?? null,
            'tenant_db'  => $this->tenantDb,
        ]);

        return $this->googleService;
    }

    public function handle()
    {
        $this->ensureTenantDbLoaded();
        $service = $this->getGoogleService();

        $pageToken = null;
        $syncToken = $this->synchronization->token;

        do {
            $options = compact('pageToken', 'syncToken');

            try {
                $list = $this->getGoogleRequest($service, $options);
            } catch (\Google_Service_Exception $e) {
                if ($e->getCode() === 410) {
                    Log::warning('SynchronizeResource: Sync token expired, resetting', [
                        'account_id' => $this->synchronizable->id ?? null,
                        'tenant_db'  => $this->tenantDb,
                    ]);
                    $this->synchronization->update(['token' => null]);
                    $this->dropAllSyncedItems();
                    return $this->handle();
                }
                throw $e;
            }

            foreach ($list->getItems() as $item) {
                $this->syncItem($item);
            }

            $pageToken = $list->getNextPageToken();
        } while ($pageToken);

        $this->synchronization->update([
            'token' => $list->getNextSyncToken(),
            'last_synchronized_at' => now(),
        ]);

        Log::info('SynchronizeResource: Sync completed', [
            'account_id' => $this->synchronizable->id ?? null,
            'tenant_db'  => $this->tenantDb,
        ]);
    }

    abstract public function getGoogleRequest($service, $options);
    abstract public function syncItem($item);
    abstract public function dropAllSyncedItems();
}
