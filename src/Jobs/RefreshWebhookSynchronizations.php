<?php

namespace Webkul\Google\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Webkul\Google\Models\Synchronization;
use Illuminate\Support\Facades\Log;
use Webkul\Master\Models\AdminUserTenant;
use Illuminate\Support\Facades\Config;

class RefreshWebhookSynchronizations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public $uniqueFor = 55; // ⬅️ job stays unique for 55 seconds

    public function handle()
    {
        $tenants = AdminUserTenant::on('mysql')->get();

        foreach ($tenants as $tenant) {
            try {
                Config::set('database.connections.tenant.database', $tenant->tenant_db);
                DB::purge('tenant');
                DB::reconnect('tenant');

                Log::info("Running RefreshWebhookSynchronizations for DB: {$tenant->tenant_db}");

                Synchronization::query()
                    ->whereNotNull('resource_id')
                    ->where(function ($q) {
                        $q->whereNull('expired_at')
                            ->orWhere('expired_at', '<', now()->addDays(2));
                    })
                    ->get()
                    ->each->refreshWebhook();
            } catch (\Exception $e) {
                Log::error("Error in RefreshWebhookSynchronizations for DB {$tenant->tenant_db}: " . $e->getMessage());
            }
        }
    }
}
