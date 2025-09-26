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

class PeriodicSynchronizations implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    protected $tenantDb;
    public $uniqueFor = 55; // job stays unique for 55 seconds

    public function __construct(string $tenantDb)
    {
        $this->tenantDb = $tenantDb;
    }

    public function handle()
    {
        Log::info('PeriodicSynchronizations: START', ['tenant_db' => $this->tenantDb]);

        // Switch to tenant database
        Config::set('database.connections.tenant.database', $this->tenantDb);
        DB::purge('tenant');
        DB::reconnect('tenant');
        Config::set('database.default', 'tenant');

        Log::info('PeriodicSynchronizations: DB switched', [
            'connection' => DB::getDefaultConnection()
        ]);

        $count = Synchronization::whereNull('resource_id')->count();
        Log::info('PeriodicSynchronizations: records found', ['count' => $count]);

        Synchronization::whereNull('resource_id')->get()->each(function ($sync) {
            Log::info('PeriodicSynchronizations: pinging', ['sync_id' => $sync->id]);
            $sync->ping();
        });

        Log::info('PeriodicSynchronizations: END', ['tenant_db' => $this->tenantDb]);
    }
}
