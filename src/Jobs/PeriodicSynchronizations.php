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

    public $uniqueFor = 55; // Job stays unique for 55 seconds

    public function handle()
    {
        Log::info("PeriodicSynchronizations job started");

        $tenants = AdminUserTenant::all();
        Log::info("Found " . $tenants->count() . " tenants");

        foreach ($tenants as $tenant) {
            Log::info("Processing tenant", ['tenant_db' => $tenant->tenant_db, 'domain' => $tenant->domain]);

            try {
                // Switch to tenant DB
                Config::set('database.connections.tenant.database', $tenant->tenant_db);
                DB::purge('tenant');
                DB::reconnect('tenant');
                Config::set('database.default', 'tenant');

                Log::info("Switched to tenant database", ['database' => $tenant->tenant_db]);

                // Fetch synchronizations with no resource_id
                $synchronizations = Synchronization::on('tenant')
                    ->whereNotNull('resource_id')
                    ->get();

                Log::info("Found " . $synchronizations->count() . " synchronizations to ping", [
                    'tenant_db' => $tenant->tenant_db
                ]);

                foreach ($synchronizations as $sync) {
                    Log::info("Pinging synchronization {$sync->id}");
                    $sync->ping();
                    Log::info("Ping completed for synchronization {$sync->id}");
                }

            } catch (\Exception $e) {
                Log::error("Error processing tenant {$tenant->tenant_db}: {$e->getMessage()}", [
                    'tenant_db' => $tenant->tenant_db,
                    'trace' => $e->getTraceAsString()
                ]);
            }
        }

        Log::info("PeriodicSynchronizations job completed");
    }
}
