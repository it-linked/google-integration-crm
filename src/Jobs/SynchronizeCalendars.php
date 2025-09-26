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

    public function getGoogleRequest($service, $options)
    {
        Log::info('SynchronizeCalendars: fetching calendars', [
            'account_id' => $this->synchronizable->id ?? null,
            'tenant_db' => $this->tenantDb,
        ]);

        return $service->calendarList->listCalendarList($options);
    }

    public function syncItem($googleCalendar)
    {
        Log::info('SynchronizeCalendars: processing calendar', [
            'google_id' => $googleCalendar->id,
            'tenant_db' => $this->tenantDb,
        ]);

        if ($googleCalendar->deleted) {
            return $this->synchronizable->calendars()
                ->where('google_id', $googleCalendar->id)
                ->get()
                ->each
                ->delete();
        }

        if ($googleCalendar->accessRole !== 'owner') return;

        $calendar = $this->synchronizable->calendars()->updateOrCreate(
            ['google_id' => $googleCalendar->id],
            [
                'name' => $googleCalendar->summary,
                'color' => $googleCalendar->backgroundColor,
                'timezone' => $googleCalendar->timeZone,
            ]
        );

        Log::info('SynchronizeCalendars: calendar stored/updated', [
            'db_id' => $calendar->id,
            'google_id' => $googleCalendar->id,
            'tenant_db' => $this->tenantDb,
        ]);
    }

    public function dropAllSyncedItems()
    {
        $this->synchronizable->calendars()->delete();

        Log::warning('SynchronizeCalendars: dropped all calendars', [
            'account_id' => $this->synchronizable->id ?? null,
            'tenant_db'  => $this->tenantDb,
        ]);
    }
}
