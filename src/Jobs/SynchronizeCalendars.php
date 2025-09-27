<?php

namespace Webkul\Google\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;
use Google_Service_Exception;

class SynchronizeCalendars extends SynchronizeResource implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function getGoogleRequest($service, $options)
    {
        $allCalendars = [];

        try {
            $pageToken = null;
            do {
                if ($pageToken) {
                    $options['pageToken'] = $pageToken;
                } else {
                    unset($options['pageToken']);
                }

                $response = $service->calendarList->listCalendarList($options);

                $allCalendars = array_merge($allCalendars, $response->getItems());
                $pageToken = $response->getNextPageToken();
            } while ($pageToken);
        } catch (Google_Service_Exception $e) {
            Log::error('SynchronizeCalendars: Google API error', [
                'account_id' => $this->synchronizable->id,
                'tenant_db' => $this->tenantDb,
                'error' => $e->getMessage(),
            ]);

            return [];
        }

        return $allCalendars;
    }

    public function syncItem($googleCalendar)
    {
        $calendar = $this->synchronizable->calendars()->updateOrCreate(
            ['google_id' => $googleCalendar->id],
            [
                'name'     => $googleCalendar->summary,
                'color'    => $googleCalendar->backgroundColor ?? '#9fe1e7',
                'timezone' => $googleCalendar->timeZone ?? 'UTC',
                'is_primary' => $googleCalendar->primary ?? false,
            ]
        );

        Log::info('SynchronizeCalendars: calendar synced', [
            'calendar_id' => $calendar->id,
            'google_id' => $googleCalendar->id,
            'account_id' => $this->synchronizable->id,
            'tenant_db' => $this->tenantDb,
        ]);

        return $calendar;
    }

    public function dropAllSyncedItems()
    {
        $this->synchronizable->calendars()->delete();

        Log::warning('SynchronizeCalendars: dropped all calendars', [
            'account_id' => $this->synchronizable->id,
            'tenant_db' => $this->tenantDb,
        ]);
    }
}
