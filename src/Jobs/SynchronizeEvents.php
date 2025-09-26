<?php

namespace Webkul\Google\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SynchronizeEvents extends SynchronizeResource implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function getGoogleRequest($service, $options)
    {
        $options['singleEvents'] = true;

        if ($this->synchronization->token) {
            // When using syncToken, cannot use orderBy or time range
            unset($options['timeMin'], $options['timeMax'], $options['orderBy']);
            $options['syncToken'] = $this->synchronization->token;
        } else {
            // Initial sync or full sync: order by startTime
            $options['orderBy'] = 'startTime';
            // Optionally, set timeMin/timeMax here
            // $options['timeMin'] = Carbon::now()->subYears(2)->toRfc3339String();
            // $options['timeMax'] = Carbon::now()->toRfc3339String();
        }

        Log::info('SynchronizeEvents: fetching events', [
            'account_id' => $this->synchronizable->id ?? null,
            'tenant_db' => $this->tenantDb,
            'sync_token' => $this->synchronization->token ?? null,
        ]);

        $allEvents = [];
        $pageToken = $options['pageToken'] ?? null;

        do {
            if ($pageToken) {
                $options['pageToken'] = $pageToken;
            } else {
                unset($options['pageToken']);
            }

            $response = $service->events->listEvents($this->synchronizable->google_id, $options);

            $allEvents = array_merge($allEvents, $response->getItems());
            $pageToken = $response->getNextPageToken();
        } while ($pageToken);

        return $allEvents;
    }

    public function syncItem($googleEvent)
    {
        $start = Carbon::parse($googleEvent->start->dateTime ?? $googleEvent->start->date);
        $end   = Carbon::parse($googleEvent->end->dateTime ?? $googleEvent->end->date);

        if ($googleEvent->status === 'cancelled') {
            $this->synchronizable->events()->where('google_id', $googleEvent->id)->delete();
            return;
        }

        if ($start->isPast()) return;

        $event = $this->synchronizable->events()->updateOrCreate(
            ['google_id' => $googleEvent->id],
            []
        );

        $activity = $event->activity()->updateOrCreate(
            ['id' => $event->activity_id],
            [
                'title' => $googleEvent->summary,
                'comment' => $googleEvent->description ?? '',
                'schedule_from' => $start,
                'schedule_to' => $end,
                'user_id' => $this->synchronizable->account->user_id,
                'type' => 'meeting',
            ]
        );

        $event->update(['activity_id' => $activity->id]);

        Log::info('SynchronizeEvents: event synced', [
            'event_id' => $event->id,
            'google_id' => $googleEvent->id,
            'tenant_db' => $this->tenantDb,
        ]);
    }

    public function dropAllSyncedItems()
    {
        $this->synchronizable->events()->delete();

        Log::warning('SynchronizeEvents: dropped all events', [
            'account_id' => $this->synchronizable->id ?? null,
            'tenant_db'  => $this->tenantDb,
        ]);
    }
}
