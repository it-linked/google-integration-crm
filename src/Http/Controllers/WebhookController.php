<?php

namespace Webkul\Google\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Webkul\Google\Models\Synchronization;
use Webkul\Google\Jobs\SynchronizeEvents;

class WebhookController extends Controller
{
    /**
     * Handle incoming Google Calendar push notifications.
     */
    public function __invoke(Request $request): void
    {
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
                'channel_id' => $request->header('x-goog-channel-id'),
                'resource_id' => $request->header('x-goog-resource-id'),
            ]);
            return;
        }

        switch ($state) {
            case 'exists':
                /**
                 * A new or updated event exists.
                 * Refresh the sync token and queue a delta sync.
                 */
                Log::info('Google webhook: event created/updated.', [
                    'sync_id' => $sync->id,
                ]);

                // Update last ping so periodic job knows it’s alive
                $sync->ping();

                // Dispatch incremental sync job (make sure showDeleted=true in job)
                SynchronizeEvents::dispatch($sync->calendar);
                break;

            case 'notExists':
                Log::info('Google webhook: resource deleted.', [
                    'sync_id' => $sync->id,
                ]);

                // Delete linked google_events and activities
                $sync->calendar->events()->delete();

                // Mark the synchronization as expired
                $sync->update(['expired_at' => now()]);
                break;

            case 'sync':
                /**
                 * Initial channel sync confirmation.
                 * Usually safe to ignore.
                 */
                Log::info('Google webhook: sync confirmation.', [
                    'sync_id' => $sync->id,
                ]);
                break;

            default:
                Log::notice('Google webhook: unhandled resource state.', [
                    'state' => $state,
                    'sync_id' => $sync->id,
                ]);
        }
    }
}
