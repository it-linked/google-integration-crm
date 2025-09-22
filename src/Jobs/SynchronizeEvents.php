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
     * Get the Google request (all events from the calendar).
     */
    public function getGoogleRequest(mixed $service, mixed $options): mixed
    {
        return $service->events->listEvents(
            $this->synchronizable->google_id,
            $options
        );
    }

    /**
     * Sync individual Google Event.
     */
    public function syncItem($googleEvent)
    {
        Log::info('SynchronizeEvents: processing item', [
            'google_id' => $googleEvent->id,
            'status' => $googleEvent->status,
            'summary' => $googleEvent->summary,
            'start' => $googleEvent->start,
            'end' => $googleEvent->end,
        ]);

        // Delete cancelled events
        if ($googleEvent->status === 'cancelled') {
            $this->synchronizable->events()
                ->where('google_id', $googleEvent->id)
                ->delete();

            Log::warning('SynchronizeEvents: deleting cancelled event', [
                'google_id' => $googleEvent->id
            ]);
            return;
        }

        // Handle recurring events by skipping past occurrences
        $startDatetime = $this->parseDatetime($googleEvent->start);
        if ($startDatetime->isPast()) {
            Log::info('SynchronizeEvents: skipped (past event)', [
                'google_id' => $googleEvent->id
            ]);
            return;
        }

        // Create or update event record
        $event = $this->synchronizable->events()->updateOrCreate(
            ['google_id' => $googleEvent->id],
            []
        );

        // Create or update activity linked to the event
        $activity = $event->activity()->updateOrCreate(
            ['id' => $event->activity_id],
            [
                'title' => $googleEvent->summary,
                'comment' => $googleEvent->description ?? '',
                'schedule_from' => $startDatetime,
                'schedule_to' => $this->parseDatetime($googleEvent->end),
                'user_id' => $this->synchronizable->account->user_id,
                'type' => 'meeting',
            ]
        );

        $event->update(['activity_id' => $activity->id]);

        Log::info('SynchronizeEvents: event record stored/updated', [
            'db_event_id' => $event->id,
            'google_id' => $googleEvent->id
        ]);

        Log::info('SynchronizeEvents: activity stored/updated', [
            'activity_id' => $activity->id,
            'google_event_id' => $googleEvent->id
        ]);
    }

    /**
     * Drop all synced items.
     */
    public function dropAllSyncedItems()
    {
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
