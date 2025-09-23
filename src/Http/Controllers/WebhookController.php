<?php

namespace Webkul\Google\Http\Controllers;

use Illuminate\Http\Request;
use Webkul\Google\Models\Synchronization;
use Illuminate\Support\Facades\Log;

class WebhookController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function __invoke(Request $request): void
    {
        Log::info('Webhook calling test');
        if ($request->header('x-goog-resource-state') !== 'exists') {
            return;
        }

        Synchronization::query()
            ->where('id', $request->header('x-goog-channel-id'))
            ->where('resource_id', $request->header('x-goog-resource-id'))
            ->firstOrFail()
            ->ping();
    }
}
