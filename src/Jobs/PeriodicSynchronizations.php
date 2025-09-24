<?php

namespace Webkul\Google\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Webkul\Google\Models\Synchronization;
use Illuminate\Support\Facades\Log;

class PeriodicSynchronizations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Handle the job.
     *
     * @return void
     */
    public function handle()
    {
        Log::info('PeriodicSynchronizations: job started');

        $synchronizations = Synchronization::whereNull('resource_id')->get();

        Log::info('PeriodicSynchronizations: found synchronizations', [
            'count' => $synchronizations->count(),
            'ids' => $synchronizations->pluck('id')->toArray(),
        ]);

        $synchronizations->each(function ($sync) {
            try {
                Log::info('PeriodicSynchronizations: pinging synchronization', [
                    'id' => $sync->id,
                ]);
                $sync->ping();
            } catch (\Throwable $e) {
                Log::error('PeriodicSynchronizations: failed to ping', [
                    'id' => $sync->id,
                    'error' => $e->getMessage(),
                ]);
            }
        });

        Log::info('PeriodicSynchronizations: job finished');
    }
}
