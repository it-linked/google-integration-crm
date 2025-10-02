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

    /**
     * Fetch calendars from Google
     */
    public function getGoogleRequest($service, $options)
    {
        $allCalendars = [];

        try {
            $pageToken = null;

            do {
                if ($pageToken) $options['pageToken'] = $pageToken;
                else unset($options['pageToken']);

                $response = $service->calendarList->listCalendarList($options);

                $allCalendars = array_merge($allCalendars, $response->getItems());
                $pageToken = $response->getNextPageToken();
                $this->lastResponse = $response;

            } while ($pageToken);

        } catch (Google_Service_Exception $e) {
            Log::error('SynchronizeCalendars: Google API error', [
                'id' => $this->synchronizable->id ?? null,
                'type' => get_class($this->synchronizable),
                'error' => $e->getMessage(),
                'tenant_db' => $this->tenantDb,
            ]);

            return [];
        }

        return $allCalendars;
    }

    /**
     * Sync a single calendar item
     */
    public function syncItem($googleCalendar)
    {
        if ($this->synchronizable instanceof \Webkul\Google\Models\Account) {
            $this->synchronizable->calendars()->updateOrCreate(
                ['google_id' => $googleCalendar->id],
                [
                    'name'      => $googleCalendar->summary,
                    'color'     => $googleCalendar->backgroundColor ?? '#9fe1e7',
                    'timezone'  => $googleCalendar->timeZone ?? 'UTC',
                    'is_primary'=> $googleCalendar->primary ?? false,
                ]
            );
        } elseif ($this->synchronizable instanceof \Webkul\Google\Models\Calendar) {
            $this->synchronizable->update([
                'name'      => $googleCalendar->summary,
                'color'     => $googleCalendar->backgroundColor ?? '#9fe1e7',
                'timezone'  => $googleCalendar->timeZone ?? 'UTC',
                'is_primary'=> $googleCalendar->primary ?? false,
            ]);
        } else {
            Log::warning('SynchronizeCalendars: Unknown synchronizable type', [
                'type' => get_class($this->synchronizable)
            ]);
        }
    }

    /**
     * Drop all synced calendars (full reset)
     */
    public function dropAllSyncedItems()
    {
        if ($this->synchronizable instanceof \Webkul\Google\Models\Account) {
            $this->synchronizable->calendars()->delete();
            Log::warning('Dropped all calendars for account', [
                'account_id' => $this->synchronizable->id ?? null,
                'tenant_db' => $this->tenantDb,
            ]);
        } elseif ($this->synchronizable instanceof \Webkul\Google\Models\Calendar) {
            $this->synchronizable->delete();
            Log::warning('Dropped calendar', [
                'calendar_id' => $this->synchronizable->id ?? null,
                'tenant_db' => $this->tenantDb,
            ]);
        }
    }
}
