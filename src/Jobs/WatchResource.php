<?php

namespace Webkul\Google\Jobs;

use Carbon\Carbon;
use Illuminate\Support\Facades\Log;

abstract class WatchResource
{
    protected $synchronizable;
    protected ?\Google_Service_Calendar $googleService = null;

    /**
     * Create a new job instance.
     *
     * @param  mixed  $synchronizable
     * @return void
     */
    public function __construct($synchronizable)
    {
        $this->synchronizable = $synchronizable;
    }

    /**
     * Execute the job.
     *
     * @return void
     */
    public function handle()
    {
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
            ]);
        }
    }

    /**
     * Lazy-load Google service instance.
     */
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

    /**
     * Get the google request.
     *
     * @param  mixed  $service
     * @param  mixed  $channel
     * @return mixed
     */
    abstract public function getGoogleRequest($service, $channel);
}
