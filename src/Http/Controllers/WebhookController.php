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
        $host = $request->getHost();
        Log::info("Webhook received from host: {$host}", ['headers' => $request->headers->all()]);

        $tenant = AdminUserTenant::where('domain', $host)->first();

        if (!$tenant) {
            Log::warning("Tenant not found for host: {$host}");
            return response()->json(['error' => 'Tenant not found'], 404);
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
