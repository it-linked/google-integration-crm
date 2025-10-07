<?php

namespace Webkul\Google\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Google\Models\Synchronization;
use Webkul\Master\Models\AdminUserTenant;

class WebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        $host          = $request->getHost();
        $channelId     = $request->header('x-goog-channel-id');
        $resourceId    = $request->header('x-goog-resource-id');
        $resourceState = $request->header('x-goog-resource-state');

        Log::info('📅 Google Calendar webhook received', [
            'host'           => $host,
            'channel_id'     => $channelId,
            'resource_id'    => $resourceId,
            'resource_state' => $resourceState,
        ]);

        // ── Detect Tenant by Host + Calendar Resource ID ─────────────────────────────
        $tenant = AdminUserTenant::where('domain', $host)
            ->where('meta->google->calendar->resource_id', $resourceId)
            ->first();

        if (! $tenant) {
            Log::warning('❌ Tenant not found or resource_id mismatch', [
                'host'        => $host,
                'resource_id' => $resourceId,
            ]);
            return response()->json(['error' => 'Tenant not found or invalid resource ID'], 404);
        }


        // Switch to tenant DB
        Config::set('database.connections.tenant.database', $tenant->tenant_db);
        DB::purge('tenant');
        DB::reconnect('tenant');
        Config::set('database.default', 'tenant');

        Log::info("Switched to tenant DB", ['database' => $tenant->tenant_db]);

        if ($request->header('x-goog-resource-state') !== 'exists') {
            Log::info("No changes detected for tenant: {$tenant->tenant_db}");
            return response()->json(['message' => 'No changes detected'], 200);
        }

        try {
            $channelId = $request->header('x-goog-channel-id');
            $resourceId = $request->header('x-goog-resource-id');

            Log::info("Looking up Synchronization", [
                'channel_id' => $channelId,
                'resource_id' => $resourceId
            ]);

            $sync = Synchronization::on('tenant')
                ->where('id', $channelId)
                ->where('resource_id', $resourceId)
                ->firstOrFail();

            Log::info("Synchronization found: {$sync->id}");
            $sync->ping();

            Log::info("Synchronization ping completed: {$sync->id}");
            return response()->json(['message' => 'Synchronization pinged successfully'], 200);
        } catch (\Exception $e) {
            Log::error('Failed to process Google webhook', [
                'error' => $e->getMessage(),
                'channel_id' => $request->header('x-goog-channel-id'),
                'resource_id' => $request->header('x-goog-resource-id'),
            ]);

            return response()->json(['error' => 'Failed to process webhook'], 500);
        }
    }
}
