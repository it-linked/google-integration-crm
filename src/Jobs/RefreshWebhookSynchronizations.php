<?php

namespace Webkul\Google\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Webkul\Google\Models\Synchronization;

class RefreshWebhookSynchronizations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $tenantDb;
    public $uniqueFor = 55; // ⬅️ job stays unique for 55 seconds

    public function __construct(string $tenantDb)
    {
        $this->tenantDb = $tenantDb;
    }

    public function handle()
    {
        Config::set('database.connections.tenant.database', $this->tenantDb);
        DB::purge('tenant');
        DB::reconnect('tenant');
        Config::set('database.default', 'tenant');

        Synchronization::query()
            ->whereNotNull('resource_id')
            ->where(function ($q) {
                $q->whereNull('expired_at')
                    ->orWhere('expired_at', '<', now()->addDays(2));
            })
            ->get()
            ->each->refreshWebhook();
    }
}
