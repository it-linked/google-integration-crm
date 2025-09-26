<?php

namespace Webkul\Google\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class WatchEvents extends WatchResource implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function getGoogleRequest($service, $channel)
    {
        Log::info('WatchEvents: starting watch for calendar', [
            'google_id' => $this->synchronizable->google_id,
            'tenant_db' => $this->tenantDb,
        ]);

        return $service->events->watch(
            $this->synchronizable->google_id,
            $channel
        );
    }
}

