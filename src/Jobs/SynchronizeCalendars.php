<?php

namespace Webkul\Google\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SynchronizeCalendars extends SynchronizeResource implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Get the google request.
     */
    public function getGoogleRequest(mixed $service, mixed $options): mixed
    {
        Log::info('SynchronizeCalendars: starting request', [
            'account_id' => $this->synchronizable->id ?? null,
            'options'    => $options,
        ]);

        return $service->calendarList->listCalendarList($options);
    }

    /**
     * Sync a single Google calendar item.
     */
    public function syncItem($googleCalendar)
    {
        Log::info('SynchronizeCalendars: processing item', [
            'id'         => $googleCalendar->id,
            'summary'    => $googleCalendar->summary,
            'accessRole' => $googleCalendar->accessRole,
            'primary'    => property_exists($googleCalendar, 'primary') ? $googleCalendar->primary : false,
        ]);

        if ($googleCalendar->deleted) {
            Log::warning('SynchronizeCalendars: calendar marked deleted', [
                'id' => $googleCalendar->id,
            ]);

            return $this->synchronizable->calendars()
                ->where('google_id', $googleCalendar->id)
                ->get()->each->delete();
        }

        if ($googleCalendar->accessRole !== 'owner') {
            Log::warning('SynchronizeCalendars: skipped (not owner)', [
                'id'         => $googleCalendar->id,
                'accessRole' => $googleCalendar->accessRole,
            ]);
            return;
        }

        $calendar = $this->synchronizable->calendars()->updateOrCreate(
            ['google_id' => $googleCalendar->id],
            [
                'name'     => $googleCalendar->summary,
                'color'    => $googleCalendar->backgroundColor,
                'timezone' => $googleCalendar->timeZone,
            ]
        );

        Log::info('SynchronizeCalendars: calendar stored/updated', [
            'db_id' => $calendar->id,
            'google_id' => $googleCalendar->id,
        ]);
    }

    /**
     * Drop all synced items.
     */
    public function dropAllSyncedItems()
    {
        Log::warning('SynchronizeCalendars: dropping all calendars', [
            'account_id' => $this->synchronizable->id ?? null,
        ]);

        $this->synchronizable->calendars->each->delete();
    }
}
