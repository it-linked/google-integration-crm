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

                $this->lastResponse = $response;
            } while ($pageToken);
        } catch (Google_Service_Exception $e) {
            // Handle invalid sync token
            if ($e->getCode() === 410) {
                Log::warning("Sync token invalid, resetting token for full sync", [
                    'account_id' => $this->synchronizable->id,
                    'tenant_db' => $this->tenantDb,
                ]);
                $this->synchronization->update(['token' => null]);
                unset($options['syncToken']);
                return $this->getGoogleRequest($service, $options);
            }

            // Calendar not found
            if ($e->getCode() === 404) {
                Log::warning("Google calendar not found, disabling sync", [
                    'calendar_id' => $calendarId,
                    'account_id' => $this->synchronizable->id,
                    'tenant_db' => $this->tenantDb,
                ]);
                $this->synchronization->update(['active' => false]);
                return [];
            }

            // Token expired
            if ($e->getCode() === 401) {
                Log::warning("Google access token invalid or expired", [
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

        if ($googleEvent->status === 'cancelled' || $start->isPast()) {
            return; // Skip cancelled or past events
        }

        // Only sync to primary calendar
        $calendar = $this->synchronizable->calendars->firstWhere('is_primary', 1);
        if (! $calendar) return;

        $event = $calendar->events()->updateOrCreate(
            ['google_id' => $googleEvent->id],
            [] // update fields if needed
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
    }

    public function dropAllSyncedItems()
    {
        $calendar = $this->synchronizable->calendars->firstWhere('is_primary', 1);
        if ($calendar) {
            $calendar->events()->delete();
        }

        Log::warning("Dropped all events for tenant {$this->tenantDb}", [
            'account_id' => $this->synchronizable->id ?? null,
        ]);
    }
}
