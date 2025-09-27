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
        $pingedSynchronizations = []; // Track pinged syncs per DB

        $tenants = AdminUserTenant::all();

        foreach ($tenants as $tenant) {

            // Switch to tenant DB if not already done
            if (!in_array($tenant->tenant_db, $processedDatabases)) {
                try {
                    Config::set('database.connections.tenant.database', $tenant->tenant_db);
                    DB::purge('tenant');
                    DB::reconnect('tenant');
                    Config::set('database.default', 'tenant');

                    $processedDatabases[] = $tenant->tenant_db;
                    $pingedSynchronizations[$tenant->tenant_db] = [];
                } catch (\Exception $e) {
                    Log::error("Error switching to tenant DB {$tenant->tenant_db}: {$e->getMessage()}", [
                        'trace' => $e->getTraceAsString()
                    ]);
                    continue;
                }
            }

            try {
                $synchronizations = Synchronization::on('tenant')
                    ->whereNotNull('resource_id')
                    ->get();

                foreach ($synchronizations as $sync) {
                    // Skip if this synchronization was already pinged for this DB
                    if (in_array($sync->id, $pingedSynchronizations[$tenant->tenant_db])) {
                        continue;
                    }

                    $sync->ping();
                    $pingedSynchronizations[$tenant->tenant_db][] = $sync->id;
                }

                // Removed success logs to reduce log size

            } catch (\Exception $e) {
                Log::error("Error syncing tenant database {$tenant->tenant_db}: {$e->getMessage()}", [
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }
    }
}
