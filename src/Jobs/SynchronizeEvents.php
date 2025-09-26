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
        $options['singleEvents'] = true;

        if ($this->synchronization->token) {
            unset($options['timeMin'], $options['timeMax'], $options['orderBy']);
            $options['syncToken'] = $this->synchronization->token;
        } else {
            $options['orderBy'] = 'startTime';
        }

        $calendarId = $this->synchronizable->calendars->firstWhere('is_primary', 1)?->google_id 
            ?? $this->synchronizable->google_id;

        Log::info('SynchronizeEvents: fetching events', [
            'account_id' => $this->synchronizable->id ?? null,
            'tenant_db' => $this->tenantDb,
            'sync_token' => $this->synchronization->token ?? null,
            'calendar_id' => $calendarId,
        ]);

        $allEvents = [];
        $pageToken = $options['pageToken'] ?? null;

        try {
            do {
                if ($pageToken) {
                    $options['pageToken'] = $pageToken;
                } else {
                    unset($options['pageToken']);
                }

                $response = $service->events->listEvents($calendarId, $options);

                $allEvents = array_merge($allEvents, $response->getItems());
                $pageToken = $response->getNextPageToken();
            } while ($pageToken);
        } catch (Google_Service_Exception $e) {

            // Handle invalid sync token (410)
            if ($e->getCode() === 410) {
                Log::warning('Sync token invalid, resetting token for full sync', [
                    'account_id' => $this->synchronizable->id,
                    'tenant_db' => $this->tenantDb,
                ]);

                $this->synchronization->update(['token' => null]);

                unset($options['syncToken']);
                return $this->getGoogleRequest($service, $options);
            }

            if ($e->getCode() === 404) {
                Log::warning('Google calendar not found, disabling synchronization', [
                    'calendar_id' => $calendarId,
                    'account_id' => $this->synchronizable->id,
                    'tenant_db' => $this->tenantDb,
                ]);
                $this->synchronization->update(['active' => false]);
                return [];
            }

            if ($e->getCode() === 401) {
                Log::warning('Google access token invalid or expired', [
                    'account_id' => $this->synchronizable->id,
                    'tenant_db' => $this->tenantDb,
                ]);
                return [];
            }

            throw $e;
        }

        return $allEvents;
    }

    public function syncItem($googleEvent)
    {
        $start = Carbon::parse($googleEvent->start->dateTime ?? $googleEvent->start->date);
        $end   = Carbon::parse($googleEvent->end->dateTime ?? $googleEvent->end->date);

        if ($googleEvent->status === 'cancelled') {
            foreach ($this->synchronizable->calendars as $calendar) {
                $calendar->events()->where('google_id', $googleEvent->id)->delete();
            }
            return;
        }

        if ($start->isPast()) return;

        foreach ($this->synchronizable->calendars as $calendar) {
            $event = $calendar->events()->updateOrCreate(
                ['google_id' => $googleEvent->id],
                [] // Update fields if needed
            );

            $activity = $event->activity()->updateOrCreate(
                ['id' => $event->activity_id],
                [
                    'title' => $googleEvent->summary,
                    'comment' => $googleEvent->description ?? '',
                    'schedule_from' => $start,
                    'schedule_to' => $end,
                    'user_id' => $this->synchronizable->user->id,
                    'type' => 'meeting',
                ]
            );

            $event->update(['activity_id' => $activity->id]);

            Log::info('SynchronizeEvents: event synced', [
                'event_id' => $event->id,
                'google_id' => $googleEvent->id,
                'calendar_id' => $calendar->id,
                'tenant_db' => $this->tenantDb,
            ]);
        }
    }

    public function dropAllSyncedItems()
    {
        foreach ($this->synchronizable->calendars as $calendar) {
            $calendar->events()->delete();
        }

        Log::warning('SynchronizeEvents: dropped all events', [
            'account_id' => $this->synchronizable->id ?? null,
            'tenant_db'  => $this->tenantDb,
        ]);
    }
}
