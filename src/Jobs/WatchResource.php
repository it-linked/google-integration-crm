<?php

namespace Webkul\Google\Jobs;

use Carbon\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

abstract class WatchResource
{
    protected $synchronizable;
    protected ?\Google_Service_Calendar $googleService = null;
    protected ?string $tenantDb = null;
    protected bool $tenantDbLoaded = false;

    /**
     * Create a new job instance.
     *
     * @param  mixed  $synchronizable
     * @param  string|null $tenantDb
     */
    public function __construct($synchronizable, ?string $tenantDb = null)
    {
        $this->synchronizable = $synchronizable;
        $this->tenantDb = $tenantDb;
    }

    /**
     * Ensure the tenant DB connection is loaded.
     */
    protected function ensureTenantDbLoaded(): void
    {
        if ($this->tenantDbLoaded || !$this->tenantDb) return;

        Config::set('database.connections.tenant.database', $this->tenantDb);
        DB::purge('tenant');
        DB::reconnect('tenant');

        $this->tenantDbLoaded = true;
    }

    /**
     * Execute the job.
     */
    public function handle()
    {
        $this->ensureTenantDbLoaded();

        $synchronization = $this->synchronizable->synchronization;

        try {
            $response = $this->getGoogleRequest(
                $this->getGoogleService(),
                $synchronization->asGoogleChannel()
            );

            $synchronization->update([
                'resource_id' => $response->getResourceId(),
                'expired_at'  => Carbon::createFromTimestampMs($response->getExpiration()),
            ]);

        } catch (\Google_Service_Exception $e) {
            Log::warning('WatchResource: Google push notification failed', [
                'error' => $e->getMessage(),
                'account_id' => $this->synchronizable->id ?? null,
                'tenant_db' => $this->tenantDb,
            ]);
        }
    }

    /**
     * Lazy-load Google service instance.
     */
    protected function getGoogleService(): \Google_Service_Calendar
    {
        if ($this->googleService) return $this->googleService;

        $this->ensureTenantDbLoaded();

        $this->googleService = $this->synchronizable->getGoogleService('Calendar');

        Log::info('Google service initialized', [
            'account_id'=> $this->synchronizable->id ?? null,
            'tenant_db' => $this->tenantDb,
        ]);

        return $this->googleService;
    }

    /**
     * Get the google request.
     */
    abstract public function getGoogleRequest($service, $channel);
}

