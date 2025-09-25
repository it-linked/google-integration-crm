<?php

namespace Webkul\Google\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Webkul\Google\Models\Synchronization;
use Webkul\Master\Models\AdminUserTenant;

class PeriodicSynchronizations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Handle the job.
     */
    public function handle()
    {
        Log::info('PeriodicSynchronizations: job started');

        // Fetch all tenants
        $tenants = AdminUserTenant::all();

        foreach ($tenants as $tenant) {
            try {
                // Switch to tenant database
                Config::set('database.connections.tenant.database', $tenant->tenant_db);
                DB::purge('tenant');
                DB::reconnect('tenant');
                Config::set('database.default', 'tenant');

                Log::info('Switched to tenant DB for Google sync', ['tenant' => $tenant->domain, 'database' => $tenant->tenant_db]);

                // Lazy load synchronizations
                Synchronization::whereNull('resource_id')->lazy()->each(function ($sync) {
                    try {
                        Log::info('Pinging synchronization', ['id' => $sync->id]);
                        $sync->ping();
                    } catch (\Throwable $e) {
                        Log::error('Failed to ping synchronization', [
                            'id'    => $sync->id,
                            'error' => $e->getMessage(),
                        ]);
                    }
                });
            } catch (\Throwable $e) {
                Log::error('Failed processing tenant', [
                    'tenant' => $tenant->domain,
                    'error'  => $e->getMessage(),
                ]);
            }
        }

        Log::info('PeriodicSynchronizations: job finished');
    }
}
