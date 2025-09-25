<?php

namespace Webkul\Google\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Google\Models\Synchronization;
use Webkul\Google\Jobs\SynchronizeEvents;

class WebhookController extends Controller
{
    protected bool $dbSwitched = false;

    protected function ensureTenantDb(): void
    {
        if ($this->dbSwitched) return;

        // Assume the tenant DB name is passed in a header
        $tenantDb = request()->header('x-app-tenant-db');

        if (! $tenantDb) {
            Log::error('Tenant DB header missing for Google webhook');
            throw new \Exception('Tenant database not provided.');
        }

        config(['database.connections.tenant.database' => $tenantDb]);
        DB::purge('tenant');
        DB::reconnect('tenant');
        DB::connection('tenant')->getPdo();
        app('config')->set('database.default', 'tenant');

        Log::info('Tenant DB switched for Google webhook: ' . $tenantDb);

        $this->dbSwitched = true;
    }

    public function __invoke(Request $request): void
    {
        $this->ensureTenantDb();

        Log::info('Google Calendar webhook hit', [
            'headers' => $request->headers->all(),
            'body'    => $request->all(),
        ]);

        $state = $request->header('x-goog-resource-state');

        $sync = Synchronization::query()
            ->where('id', $request->header('x-goog-channel-id'))
            ->where('resource_id', $request->header('x-goog-resource-id'))
            ->first();

        if (! $sync) {
            Log::warning('Google webhook: No matching Synchronization record.', [
                'channel_id'  => $request->header('x-goog-channel-id'),
                'resource_id' => $request->header('x-goog-resource-id'),
            ]);
            return;
        }

        switch ($state) {
            case 'exists':
                Log::info('Google webhook: event created/updated.', ['sync_id' => $sync->id]);
                $sync->ping();
                SynchronizeEvents::dispatch($sync->calendar);
                break;

            case 'notExists':
                Log::info('Google webhook: resource deleted.', ['sync_id' => $sync->id]);
                $sync->calendar->events()->delete();
                $sync->update(['expired_at' => now()]);
                break;

            case 'sync':
                Log::info('Google webhook: sync confirmation.', ['sync_id' => $sync->id]);
                break;

            default:
                Log::notice('Google webhook: unhandled resource state.', [
                    'state' => $state,
                    'sync_id' => $sync->id,
                ]);
        }
    }
}
