<?php

namespace Webkul\Google\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SynchronizeEvents extends SynchronizeResource implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Get all events from Google Calendar.
     */
    public function getGoogleRequest($service, $options)
    {
        $service = $this->getGoogleService();

        $options['singleEvents'] = true;
        $options['orderBy']      = 'startTime';

        // ✅ Use saved sync token if present
        if ($this->synchronization->token) {
            unset($options['timeMin'], $options['timeMax']); // Google forbids these when using syncToken
            $options['syncToken'] = $this->synchronization->token;
        }

        Log::info('SynchronizeEvents: starting request', [
            'account_id' => $this->synchronizable->id ?? null,
            'options'    => $options,
        ]);

        return $service->events->listEvents($this->synchronizable->google_id, $options);
    }

    /**
     * Main synchronization function.
     */
    public function synchronize(): void
    {
        $service = $this->getGoogleService();
        $options = [
            'timeMin' => now()->subYears(1)->toAtomString(),
            'timeMax' => now()->addYears(2)->toAtomString(),
        ];

        $googleEvents = $this->getGoogleRequest($service, $options);

        do {
            $googleIds = collect($googleEvents->getItems())->pluck('id')->toArray();

            // Delete missing events
            $deletedCount = $this->synchronizable->events()
                ->whereNotIn('google_id', $googleIds)
                ->delete();

            Log::info('SynchronizeEvents: deleted missing events', [
                'deleted_count' => $deletedCount,
                'account_id'    => $this->synchronizable->id,
            ]);

            foreach ($googleEvents->getItems() as $googleEvent) {
                $this->syncItem($googleEvent);
            }

            // ✅ Paginate if more pages
            $pageToken = $googleEvents->getNextPageToken();
            if ($pageToken) {
                $options['pageToken'] = $pageToken;
                $googleEvents = $service->events->listEvents($this->synchronizable->google_id, $options);
            } else {
                // ✅ Save nextSyncToken for incremental sync
                $this->synchronization->update([
                    'token'           => $googleEvents->getNextSyncToken(),
                    'last_synchronized_at' => now(),
                ]);
            }
        } while ($pageToken);
    }

    /**
     * Sync individual Google Event.
     */
    public function syncItem($googleEvent)
    {
        $startDatetime = $this->parseDatetime($googleEvent->start);
        $endDatetime   = $this->parseDatetime($googleEvent->end);

        Log::info('SynchronizeEvents: processing item', [
            'google_id' => $googleEvent->id,
            'status'    => $googleEvent->status,
            'summary'   => $googleEvent->summary,
            'start'     => $startDatetime->toDateTimeString(),
            'end'       => $endDatetime->toDateTimeString(),
        ]);

        if ($googleEvent->status === 'cancelled') {
            $this->synchronizable->events()
                ->where('google_id', $googleEvent->id)
                ->delete();

            Log::warning('SynchronizeEvents: deleting cancelled event', [
                'google_id' => $googleEvent->id,
            ]);

            return;
        }

        if ($startDatetime->isPast()) {
            Log::info('SynchronizeEvents: skipped (past event)', [
                'google_id' => $googleEvent->id,
            ]);
            return;
        }

        $event = $this->synchronizable->events()->updateOrCreate(
            ['google_id' => $googleEvent->id],
            []
        );

        $activity = $event->activity()->updateOrCreate(
            ['id' => $event->activity_id],
            [
                'title'         => $googleEvent->summary,
                'comment'       => $googleEvent->description ?? '',
                'schedule_from' => $startDatetime,
                'schedule_to'   => $endDatetime,
                'user_id'       => $this->synchronizable->account->user_id,
                'type'          => 'meeting',
            ]
        );

        $event->update(['activity_id' => $activity->id]);

        Log::info('SynchronizeEvents: event and activity stored/updated', [
            'event_id'    => $event->id,
            'activity_id' => $activity->id,
            'google_id'   => $googleEvent->id,
        ]);
    }

    /**
     * Drop all synced events.
     */
    public function dropAllSyncedItems()
    {
        Log::warning('SynchronizeEvents: dropping all events', [
            'account_id' => $this->synchronizable->id ?? null,
        ]);

        $this->synchronizable->events()->delete();
    }

    /**
     * Parse datetime from Google Event.
     */
    protected function parseDatetime($googleDatetime)
    {
        $rawDatetime = $googleDatetime->dateTime ?? $googleDatetime->date;
        return Carbon::parse($rawDatetime);
    }
}
