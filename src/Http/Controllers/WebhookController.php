<?php

namespace Webkul\Google\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Config;
use Webkul\Google\Models\Synchronization;
use Webkul\Google\Jobs\SynchronizeEvents;
use App\Models\AdminUserTenant; // your tenant mapping model

class WebhookController extends Controller
{
    protected bool $dbSwitched = false;

    protected function ensureTenantDb(Request $request): void
    {
        if ($this->dbSwitched) return;

        $host = $request->getHost();
        $tenant = AdminUserTenant::where('domain', $host)->first();

        if (! $tenant) {
            Log::error('Tenant not found for host', ['host' => $host]);
            throw new \Exception('Tenant database not provided.');
        }

        Config::set('database.connections.tenant.database', $tenant->tenant_db);
        DB::purge('tenant');
        DB::reconnect('tenant');
        Config::set('database.default', 'tenant');

        Log::info('Switched to tenant DB for Google webhook', ['database' => $tenant->tenant_db]);

        $this->dbSwitched = true;
    }

    public function __invoke(Request $request): void
    {
        $this->ensureTenantDb($request);

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
