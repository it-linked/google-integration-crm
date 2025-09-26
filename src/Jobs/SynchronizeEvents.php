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
        $options['orderBy'] = 'startTime';

        if ($this->synchronization->token) {
            unset($options['timeMin'], $options['timeMax']);
            $options['syncToken'] = $this->synchronization->token;
        }

        Log::info('SynchronizeEvents: fetching events', [
            'account_id' => $this->synchronizable->id ?? null,
            'tenant_db' => $this->tenantDb,
        ]);

        return $service->events->listEvents($this->synchronizable->google_id, $options);
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
