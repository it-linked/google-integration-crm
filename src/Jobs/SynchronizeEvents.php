<?php

namespace Webkul\Google\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Google\Service\Calendar as GoogleCalendar;
use Google\Service\Exception as GoogleException;

class SynchronizeEvents extends SynchronizeResource implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Build the Google Calendar request.
     * Ensures deleted events are also returned.
     */
    public function getGoogleRequest(mixed $service, mixed $options): mixed
    {
        // Always expand recurring events into individual instances
        $options['singleEvents'] = true;
        $options['orderBy']      = 'startTime';

        // ✅ Critical: include deleted/cancelled events
        $options['showDeleted']  = true;

        Log::info('SynchronizeEvents: starting request', [
            'account_id' => $this->synchronizable->id ?? null,
            'options'    => $options
        ]);

        try {
            return $service->events->listEvents(
                $this->synchronizable->google_id,
                $options
            );
        } catch (GoogleException $e) {
            // Handle expired/invalid syncToken (HTTP 410)
            if ($e->getCode() === 410) {
                Log::warning('SynchronizeEvents: syncToken invalid, dropping all events.', [
                    'account_id' => $this->synchronizable->id ?? null,
                ]);
                $this->dropAllSyncedItems();
                // Force a full sync by removing syncToken
                unset($options['syncToken']);
                return $service->events->listEvents(
                    $this->synchronizable->google_id,
                    $options
                );
            }

            throw $e;
        }
    }

    /**
     * Sync a single Google Calendar event.
     */
    public function syncItem($googleEvent): void
    {
        $startDatetime = $this->parseDatetime($googleEvent->start);
        $endDatetime   = $this->parseDatetime($googleEvent->end);

        Log::info('SynchronizeEvents: processing item', [
            'google_id' => $googleEvent->id,
            'status'    => $googleEvent->status,
            'summary'   => $googleEvent->summary,
            'start'     => $startDatetime?->toDateTimeString(),
            'end'       => $endDatetime?->toDateTimeString(),
        ]);

        /**
         * ✅ Handle cancelled/deleted events.
         * Google marks deleted events as status=cancelled when showDeleted=true.
         */
        if ($googleEvent->status === 'cancelled') {
            $this->synchronizable->events()
                ->where('google_id', $googleEvent->id)
                ->delete();

            Log::warning('SynchronizeEvents: deleting cancelled event', [
                'google_id' => $googleEvent->id,
            ]);
            return;
        }

        // Skip past events if you don’t need historical syncing
        if ($startDatetime && $startDatetime->isPast()) {
            Log::info('SynchronizeEvents: skipped (past event)', [
                'google_id' => $googleEvent->id,
            ]);
            return;
        }

        // ✅ Upsert the event record
        $event = $this->synchronizable->events()->updateOrCreate(
            ['google_id' => $googleEvent->id],
            [] // Fill other event-specific columns if needed
        );

        // ✅ Upsert the related CRM activity
        $activity = $event->activity()->updateOrCreate(
            ['id' => $event->activity_id],
            [
                'title'         => $googleEvent->summary ?? '(No Title)',
                'comment'       => $googleEvent->description ?? '',
                'schedule_from' => $startDatetime,
                'schedule_to'   => $endDatetime,
                'user_id'       => $this->synchronizable->account->user_id,
                'type'          => 'meeting',
            ]
        );

        // Link activity back to event
        $event->update(['activity_id' => $activity->id]);

        Log::info('SynchronizeEvents: event stored/updated', [
            'db_event_id' => $event->id,
            'google_id'   => $googleEvent->id,
        ]);
    }

    /**
     * Drop all previously synced events (used on 410 Gone).
     */
    public function dropAllSyncedItems(): void
    {
        Log::warning('SynchronizeEvents: dropping all events', [
            'account_id' => $this->synchronizable->id ?? null,
        ]);

        $this->synchronizable->events()->delete();
    }

    /**
     * Parse start/end datetimes safely.
     */
    protected function parseDatetime($googleDatetime): ?Carbon
    {
        if (! $googleDatetime) {
            return null;
        }

        $raw = $googleDatetime->dateTime ?? $googleDatetime->date ?? null;
        return $raw ? Carbon::parse($raw) : null;
    }
}
