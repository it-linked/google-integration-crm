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
     * Google request to list events.
     */
    public function getGoogleRequest(mixed $service, mixed $options): mixed
    {
        Log::info('SynchronizeEvents: starting request', [
            'calendar_id' => $this->synchronizable->google_id ?? null,
            'options'     => $options,
        ]);

        return $service->events->listEvents(
            $this->synchronizable->google_id,
            $options
        );
    }

    /**
     * Sync a single Google event.
     */
    public function syncItem($googleEvent)
    {
        Log::info('SynchronizeEvents: processing item', [
            'google_id' => $googleEvent->id,
            'status'    => $googleEvent->status,
            'summary'   => $googleEvent->summary ?? null,
            'start'     => $googleEvent->start->dateTime ?? $googleEvent->start->date ?? null,
            'end'       => $googleEvent->end->dateTime ?? $googleEvent->end->date ?? null,
        ]);

        if ($googleEvent->status === 'cancelled') {
            Log::warning('SynchronizeEvents: deleting cancelled event', [
                'google_id' => $googleEvent->id,
            ]);

            return $this->synchronizable->events()
                ->where('google_id', $googleEvent->id)
                ->delete();
        }

        if (Carbon::now() > $this->parseDatetime($googleEvent->start)) {
            Log::info('SynchronizeEvents: skipped (past event)', [
                'google_id' => $googleEvent->id,
            ]);
            return;
        }

        $event = $this->synchronizable->events()->updateOrCreate([
            'google_id' => $googleEvent->id,
        ]);

        Log::info('SynchronizeEvents: event record stored/updated', [
            'db_event_id' => $event->id,
            'google_id'   => $googleEvent->id,
        ]);

        $activity = $event->activity()->updateOrCreate(
            ['id' => $event->activity_id],
            [
                'title'         => $googleEvent->summary,
                'comment'       => $googleEvent->description,
                'schedule_from' => $this->parseDatetime($googleEvent->start),
                'schedule_to'   => $this->parseDatetime($googleEvent->end),
                'user_id'       => $this->synchronizable->account->user_id,
                'type'          => 'meeting',
            ]
        );

        $event->update(['activity_id' => $activity->id]);

        Log::info('SynchronizeEvents: activity stored/updated', [
            'activity_id' => $activity->id,
            'google_event_id' => $googleEvent->id,
        ]);
    }

    /**
     * Drop all synced items.
     */
    public function dropAllSyncedItems()
    {
        Log::warning('SynchronizeEvents: dropping all events', [
            'calendar_id' => $this->synchronizable->google_id ?? null,
        ]);

        $this->synchronizable->events()->delete();
    }

    protected function isAllDayEvent($googleEvent)
    {
        return ! $googleEvent->start->dateTime && ! $googleEvent->end->dateTime;
    }

    protected function parseDatetime($googleDatetime)
    {
        $rawDatetime = $googleDatetime->dateTime ?: $googleDatetime->date;
        return Carbon::parse($rawDatetime);
    }
}
