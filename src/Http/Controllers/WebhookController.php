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
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request)
    {
        // ── Tenant detection ──────────────────────────────────────────────
        $host = $request->getHost();
        $tenant = AdminUserTenant::where('domain', $host)->first();

        if (!$tenant) {
            return response()->json(['error' => 'Tenant not found'], 404);
        }

        // Switch to tenant database
        Config::set('database.connections.tenant.database', $tenant->tenant_db);
        DB::purge('tenant');
        DB::reconnect('tenant');
        Config::set('database.default', 'tenant');

        Log::info('Switched to tenant DB', ['database' => $tenant->tenant_db]);

        // ── Google webhook processing ───────────────────────────────────
        if ($request->header('x-goog-resource-state') !== 'exists') {
            return response()->json(['message' => 'No changes detected'], 200);
        }

        try {
            $sync = Synchronization::query()
                ->where('id', $request->header('x-goog-channel-id'))
                ->where('resource_id', $request->header('x-goog-resource-id'))
                ->firstOrFail();

            $sync->ping();

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
