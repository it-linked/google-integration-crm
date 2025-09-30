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

    /**
     * Fetch events from Google Calendar
     */
    public function getGoogleRequest($service, $options)
    {
        // Ensure we get individual occurrences and deleted events
        $options['singleEvents'] = true;
        $options['showDeleted'] = true;

        $calendarId = $this->synchronizable->calendars
            ->firstWhere('is_primary', 1)?->google_id
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

                // Store last response for nextSyncToken
                $this->lastResponse = $response;

            } while ($pageToken);

        } catch (Google_Service_Exception $e) {
            // Handle invalid sync token
            if ($e->getCode() === 410) {
                Log::warning("Sync token invalid, resetting token for full sync", [
                    'account_id' => $this->synchronizable->id,
                    'tenant_db'  => $this->tenantDb,
                ]);
                $this->synchronization->update(['token' => null]);
                unset($options['syncToken']);
                return $this->getGoogleRequest($service, $options);
            }

            // Calendar not found
            if ($e->getCode() === 404) {
                Log::warning("Google calendar not found, disabling sync", [
                    'calendar_id' => $calendarId,
                    'account_id'  => $this->synchronizable->id,
                    'tenant_db'   => $this->tenantDb,
                ]);
                $this->synchronization->update(['active' => false]);
                return [];
            }

            // Token expired
            if ($e->getCode() === 401) {
                Log::warning("Google access token invalid or expired", [
                    'account_id' => $this->synchronizable->id,
                    'tenant_db'  => $this->tenantDb,
                ]);
                return [];
            }

            throw $e;
        }

        return $allEvents;
    }

    /**
     * Sync each event with local DB
     */
    public function syncItem($googleEvent)
    {
        $calendar = $this->synchronizable->calendars->firstWhere('is_primary', 1);
        if (!$calendar) return;

        // Handle deleted/cancelled events
        if ($googleEvent->status === 'cancelled') {
            $event = $calendar->events()->where('google_id', $googleEvent->id)->first();

            if ($event) {
                // Delete linked activity first
                if ($event->activity) {
                    $event->activity->delete();
                }
                $event->delete();

                Log::info("Deleted Google event locally", [
                    'google_id' => $googleEvent->id,
                    'tenant_db' => $this->tenantDb,
                ]);
            }

            return; // stop further processing
        }

        $start = Carbon::parse($googleEvent->start->dateTime ?? $googleEvent->start->date);
        $end   = Carbon::parse($googleEvent->end->dateTime ?? $googleEvent->end->date);

        // Optional: skip past events
        // if ($start->isPast()) return;

        // Create or update local event
        $event = $calendar->events()->updateOrCreate(
            ['google_id' => $googleEvent->id],
            [
                'start'       => $start,
                'end'         => $end,
            ]
        );

        // Create or update activity
        $activity = $event->activity()->updateOrCreate(
            ['id' => $event->activity_id],
            [
                'title'        => $googleEvent->summary,
                'comment'      => $googleEvent->description ?? '',
                'schedule_from'=> $start,
                'schedule_to'  => $end,
                'user_id'      => $this->synchronizable->user->id,
                'type'         => 'meeting',
            ]
        );

        $event->update(['activity_id' => $activity->id]);
    }

    /**
     * Drop all synced items (optional, for full reset)
     */
    public function dropAllSyncedItems()
    {
        $calendar = $this->synchronizable->calendars->firstWhere('is_primary', 1);
        if ($calendar) {
            foreach ($calendar->events as $event) {
                if ($event->activity) $event->activity->delete();
                $event->delete();
            }
        }

        Log::warning("Dropped all events for tenant {$this->tenantDb}", [
            'account_id' => $this->synchronizable->id ?? null,
        ]);
    }
}
