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

    public function getGoogleRequest(mixed $service, mixed $options): mixed
    {
        Log::info('SynchronizeCalendars: starting request', [
            'account_id' => $this->synchronizable->id ?? null,
            'options'    => $options,
        ]);

        try {
            return $service->calendarList->listCalendarList($options);
        } catch (Google_Service_Exception $e) {
            if ($e->getCode() === 410) {
                Log::warning('Sync token invalid for calendars, resetting token', [
                    'account_id' => $this->synchronizable->id,
                    'tenant_db' => $this->tenantDb,
                ]);

                $this->synchronization->update(['token' => null]);

                unset($options['syncToken']);
                return $this->getGoogleRequest($service, $options);
            }

            if ($e->getCode() === 404) {
                Log::warning('Google calendar not found, disabling synchronization', [
                    'google_id' => $this->synchronizable->google_id ?? null,
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
                // Optional: refresh token logic
                return [];
            }

            throw $e;
        }
    }

    public function syncItem($googleCalendar)
    {
        Log::info('SynchronizeCalendars: processing item', [
            'id'         => $googleCalendar->id,
            'summary'    => $googleCalendar->summary,
            'accessRole' => $googleCalendar->accessRole,
            'primary'    => property_exists($googleCalendar, 'primary') ? $googleCalendar->primary : false,
        ]);

        if ($googleCalendar->deleted ?? false) {
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
                'color'    => $googleCalendar->backgroundColor ?? null,
                'timezone' => $googleCalendar->timeZone ?? null,
            ]
        );

        Log::info('SynchronizeCalendars: calendar stored/updated', [
            'db_id' => $calendar->id,
            'google_id' => $googleCalendar->id,
        ]);
    }

    public function dropAllSyncedItems()
    {
        Log::warning('SynchronizeCalendars: dropping all calendars', [
            'account_id' => $this->synchronizable->id ?? null,
        ]);

        $this->synchronizable->calendars->each->delete();
    }
}
