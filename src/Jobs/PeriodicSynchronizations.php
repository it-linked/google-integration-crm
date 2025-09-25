<?php
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Webkul\Master\Models\AdminUserTenant; // adjust your model namespace

class PeriodicSynchronizations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function handle()
    {
        Log::info('PeriodicSynchronizations: job started');

        $tenants = AdminUserTenant::all();

        foreach ($tenants as $tenant) {
            try {
                // ── Switch tenant database ───────────────────────
                Config::set('database.connections.tenant.database', $tenant->tenant_db);
                DB::purge('tenant');
                DB::reconnect('tenant');
                Config::set('database.default', 'tenant');

                Log::info('Switched to tenant DB', ['tenant' => $tenant->domain, 'database' => $tenant->tenant_db]);

                // ── Lazy load synchronizations ─────────────────
                Synchronization::query()->lazy()->each(function ($sync) {
                    try {
                        Log::info('Pinging synchronization', ['id' => $sync->id]);
                        $sync->ping();
                    } catch (\Throwable $e) {
                        Log::error('Failed to ping sync', ['id' => $sync->id, 'error' => $e->getMessage()]);
                    }
                });
            } catch (\Throwable $e) {
                Log::error('Failed processing tenant', ['tenant' => $tenant->domain, 'error' => $e->getMessage()]);
            }
        }

        Log::info('PeriodicSynchronizations: job finished');
    }
}
