<?php

namespace Webkul\Google\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class SynchronizeCalendars extends WatchResource implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function getGoogleRequest($service, $options)
    {
        $service = $this->getGoogleService(); // lazy-load

        Log::info('SynchronizeCalendars: starting request', [
            'account_id' => $this->synchronizable->id ?? null,
            'options'    => $options,
            'tenant_db'  => $this->tenantDb,
        ]);

        return $service->calendarList->listCalendarList($options);
    }

    public function syncItem($googleCalendar)
    {
        Log::info('SynchronizeCalendars: processing item', [
            'id'         => $googleCalendar->id,
            'summary'    => $googleCalendar->summary,
            'accessRole' => $googleCalendar->accessRole,
            'primary'    => property_exists($googleCalendar, 'primary') ? $googleCalendar->primary : false,
            'tenant_db'  => $this->tenantDb,
        ]);

        if ($googleCalendar->deleted) {
            return $this->synchronizable->calendars()
                ->where('google_id', $googleCalendar->id)
                ->get()
                ->each
                ->delete();
        }

        if ($googleCalendar->accessRole !== 'owner') {
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
            'db_id'     => $calendar->id,
            'google_id' => $googleCalendar->id,
            'tenant_db' => $this->tenantDb,
        ]);
    }

    public function dropAllSyncedItems()
    {
        Log::warning('SynchronizeCalendars: dropping all calendars', [
            'account_id' => $this->synchronizable->id ?? null,
            'tenant_db'  => $this->tenantDb,
        ]);

        $this->synchronizable->calendars->each->delete();
    }
}
