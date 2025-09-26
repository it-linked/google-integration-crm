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

class PeriodicSynchronizations implements ShouldQueue
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
        // Switch to tenant database
        Config::set('database.connections.tenant.database', $this->tenantDb);
        Log::info("db name" . $this->tenantDb);
        DB::purge('tenant');
        DB::reconnect('tenant');
        Config::set('database.default', 'tenant');

        Synchronization::whereNull('resource_id')->get()->each->ping();
    }
}
