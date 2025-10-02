<?php

namespace Webkul\Google\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Google_Service_Exception;

class SynchronizeEvents extends SynchronizeResource implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function getGoogleRequest($service, $options)
    {
        $primaryCalendar = $this->getPrimaryCalendar();
        if (!$primaryCalendar) return [];

        $options['singleEvents'] = true;
        $options['showDeleted'] = true;

        $calendarId = $primaryCalendar->google_id;
        $allEvents = [];
        $pageToken = $options['pageToken'] ?? null;

        try {
            do {
                if ($pageToken) $options['pageToken'] = $pageToken;
                else unset($options['pageToken']);

                $response = $service->events->listEvents($calendarId, $options);
                $allEvents = array_merge($allEvents, $response->getItems());
                $pageToken = $response->getNextPageToken();
                $this->lastResponse = $response;
            } while ($pageToken);

        } catch (Google_Service_Exception $e) {
            if ($e->getCode() === 410) {
                Log::warning("Sync token invalid, resetting token", ['calendar_id' => $calendarId]);
                $this->synchronization->update(['token' => null]);
                unset($options['syncToken']);
                return $this->getGoogleRequest($service, $options);
            }

            if ($e->getCode() === 404) {
                Log::warning("Calendar not found, disabling sync", ['calendar_id' => $calendarId]);
                $this->synchronization->update(['active' => false]);
                return [];
            }

            if ($e->getCode() === 401) {
                Log::warning("Access token invalid or expired", ['calendar_id' => $calendarId]);
                return [];
            }

            throw $e;
        }

        return $allEvents;
    }

    public function syncItem($googleEvent)
    {
        $calendar = $this->getPrimaryCalendar();
        if (!$calendar) return;

        if ($googleEvent->status === 'cancelled') {
            $event = $calendar->events()->where('google_id', $googleEvent->id)->first();
            if ($event) {
                $event->activity?->delete();
                $event->delete();
            }
            return;
        }

        $start = Carbon::parse($googleEvent->start->dateTime ?? $googleEvent->start->date);
        $end   = Carbon::parse($googleEvent->end->dateTime ?? $googleEvent->end->date);

        $event = $calendar->events()->updateOrCreate(
            ['google_id' => $googleEvent->id],
            ['start' => $start, 'end' => $end]
        );

        $activity = $event->activity()->updateOrCreate(
            ['id' => $event->activity_id],
            [
                'title'        => $googleEvent->summary,
                'comment'      => $googleEvent->description ?? '',
                'schedule_from'=> $start,
                'schedule_to'  => $end,
                'user_id'      => $this->synchronizable->user->id ?? null,
                'type'         => 'meeting',
            ]
        );

        $event->update(['activity_id' => $activity->id]);
    }

    public function dropAllSyncedItems()
    {
        $calendar = $this->getPrimaryCalendar();
        if ($calendar) {
            foreach ($calendar->events as $event) {
                $event->activity?->delete();
                $event->delete();
            }
        }

        Log::warning("Dropped all events for tenant {$this->tenantDb}", [
            'account_id' => $this->synchronizable->id ?? null,
        ]);
    }
}
