<?php

namespace Webkul\Google\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Google\Models\Synchronization;
use Webkul\Master\Models\AdminUserTenant;

class PeriodicSynchronizations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $uniqueFor = 55;

    public function handle()
    {
        $processedDatabases = [];

        $tenants = AdminUserTenant::all();

        foreach ($tenants as $tenant) {

            // Skip if this tenant DB was already processed
            if (in_array($tenant->tenant_db, $processedDatabases)) {
                continue;
            }

            try {
                // Switch to tenant database
                Config::set('database.connections.tenant.database', $tenant->tenant_db);
                DB::purge('tenant');
                DB::reconnect('tenant');
                Config::set('database.default', 'tenant');

                // Mark this DB as processed
                $processedDatabases[] = $tenant->tenant_db;

                // Get synchronizations for this tenant
                $synchronizations = Synchronization::on('tenant')
                    ->whereNotNull('resource_id')
                    ->get();

                foreach ($synchronizations as $sync) {
                    $sync->ping();
                }

                Log::info("Successfully synced tenant database: {$tenant->tenant_db}");
            } catch (\Exception $e) {
                Log::error("Error syncing tenant database {$tenant->tenant_db}: {$e->getMessage()}", [
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
    }
}
