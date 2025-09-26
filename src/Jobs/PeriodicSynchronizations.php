<?php

namespace Webkul\Google\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Webkul\Google\Models\Synchronization;
use Illuminate\Support\Facades\Log;
use Webkul\Master\Models\AdminUserTenant;

class PeriodicSynchronizations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $uniqueFor = 55; // ⬅️ job stays unique for 55 seconds

    public function handle()
    {
        $tenants = AdminUserTenant::all();

        foreach ($tenants as $tenant) {
            try {
                // Switch to tenant database
                Config::set('database.connections.tenant.database', $tenant->tenant_db);
                DB::purge('tenant');
                DB::reconnect('tenant');
                Config::set('database.default', 'tenant');

                Log::info("Running PeriodicSynchronizations for DB: {$tenant->tenant_db}");

                Synchronization::whereNull('resource_id')->get()->each->ping();
            } catch (\Exception $e) {
                Log::error("Error in PeriodicSynchronizations for DB {$tenant->tenant_db}: " . $e->getMessage());
            }
        }
    }
}
