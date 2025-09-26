<?php

namespace Webkul\Google\Jobs;

abstract class SynchronizeResource
{
    protected $synchronizable;
    protected $synchronization;

    // Lazy-loaded Google service
    protected ?\Google_Service_Calendar $googleService = null;

    // Lazy-loaded tenant DB flag
    protected bool $tenantDbLoaded = false;

    public function __construct($synchronizable)
    {
        $this->synchronizable = $synchronizable;
        $this->synchronization = $synchronizable->synchronization;
    }

    /**
     * Ensure tenant database is selected
     */
    protected function ensureTenantDbLoaded(): void
    {
        if ($this->tenantDbLoaded) return;

        $tenantConnection = $this->synchronizable->getConnectionName(); // your tenant model must provide this
        config(['database.connections.tenant.database' => $tenantConnection]);
        \DB::setDefaultConnection('tenant');

        \Log::info('SynchronizeResource: Tenant DB switched', [
            'tenant_id' => $this->synchronizable->id,
            'connection' => \DB::getDefaultConnection()
        ]);

        $this->tenantDbLoaded = true;
    }

    /**
     * Lazy-load Google Calendar service
     */
    protected function getGoogleService(): \Google_Service_Calendar
    {
        if ($this->googleService) {
            return $this->googleService;
        }

        $this->ensureTenantDbLoaded();

        $this->googleService = $this->synchronizable->getGoogleService('Calendar');
        return $this->googleService;
    }

    public function handle()
    {
        $this->ensureTenantDbLoaded();
        $service = $this->getGoogleService();

        $pageToken = null;
        $syncToken = $this->synchronization->token;

        do {
            $tokens = compact('pageToken', 'syncToken');

            try {
                $list = $this->getGoogleRequest($service, $tokens);
            } catch (\Google_Service_Exception $e) {
                if ($e->getCode() === 410) {
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
            'token'                => $list->getNextSyncToken(),
            'last_synchronized_at' => now(),
        ]);
    }

    abstract public function getGoogleRequest($service, $options);
    abstract public function syncItem($item);
    abstract public function dropAllSyncedItems();
}
