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
    public function getGoogleRequest(mixed $service, mixed $options): mixed
    {
        // Expand recurring events into individual occurrences
        $options['singleEvents'] = true;
        $options['orderBy'] = 'startTime';

        Log::info('SynchronizeEvents: starting request', [
            'account_id' => $this->synchronizable->id ?? null,
            'options'    => $options
        ]);

        return $service->events->listEvents(
            $this->synchronizable->google_id,
            $options
        );
    }

    /**
     * Main synchronization function.
     */
    public function synchronize(): void
    {
        $service = $this->synchronizable->getGoogleService('Calendar');

        $googleEvents = $this->getGoogleRequest($service, [
            'timeMin' => now()->subYears(1)->toAtomString(),
            'timeMax' => now()->addYears(2)->toAtomString(),
        ]);

        $googleIds = collect($googleEvents->getItems())->pluck('id')->toArray();

        // Delete events in CRM that no longer exist in Google Calendar
        $deletedCount = $this->synchronizable->events()
            ->whereNotIn('google_id', $googleIds)
            ->delete();

        Log::info('SynchronizeEvents: deleted missing events from CRM', [
            'deleted_count' => $deletedCount,
            'account_id'    => $this->synchronizable->id,
        ]);

        // Sync remaining or new events
        foreach ($googleEvents->getItems() as $googleEvent) {
            $this->syncItem($googleEvent);
        }

        Log::info('SynchronizeEvents: completed synchronization', [
            'account_id' => $this->synchronizable->id,
        ]);
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

        // Skip past events if desired
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
                'title'         => $googleEvent->summary,
                'comment'       => $googleEvent->description ?? '',
                'schedule_from' => $startDatetime,
                'schedule_to'   => $endDatetime,
                'user_id'       => $this->synchronizable->account->user_id,
                'type'          => 'meeting',
            ]
        );

        $event->update(['activity_id' => $activity->id]);

        Log::info('SynchronizeEvents: event record stored/updated', [
            'db_event_id' => $event->id,
            'google_id'   => $googleEvent->id
        ]);

        Log::info('SynchronizeEvents: activity stored/updated', [
            'activity_id'     => $activity->id,
            'google_event_id' => $googleEvent->id
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
