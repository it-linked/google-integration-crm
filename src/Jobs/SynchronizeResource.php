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

    public function __construct($synchronizable, ?string $tenantDb = null)
    {
        $this->synchronizable = $synchronizable;
        $this->tenantDb = $tenantDb;
    }

    public function handle()
    {
        // ✅ Switch to tenant DB if provided
        if ($this->tenantDb) {
            Config::set('database.connections.tenant.database', $this->tenantDb);
            DB::purge('tenant');
            DB::reconnect('tenant');
            Config::set('database.default', 'tenant');

            Log::info("WatchResource: switched to tenant DB: {$this->tenantDb}");
        }

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

            Log::info('WatchResource: updated synchronization', [
                'resource_id' => $response->getResourceId(),
                'expired_at'  => Carbon::createFromTimestampMs($response->getExpiration()),
            ]);
        } catch (\Google_Service_Exception $e) {
            Log::warning('WatchResource: Google push notification failed', [
                'error'      => $e->getMessage(),
                'account_id' => $this->synchronizable->id ?? null,
            ]);
        }
    }

    protected function getGoogleService(): \Google_Service_Calendar
    {
        if ($this->googleService) {
            return $this->googleService;
        }

        Log::info('Creating Google service instance: Google_Service_Calendar');

        $this->googleService = $this->synchronizable->getGoogleService('Calendar');

        Log::info('Google service initialized');

        return $this->googleService;
    }

    abstract public function getGoogleRequest($service, $channel);
}
