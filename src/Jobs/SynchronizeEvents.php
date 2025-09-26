<?php

namespace Webkul\Google\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

class SynchronizeEvents extends WatchResource implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public function getGoogleRequest($service, $options)
    {
        $service = $this->getGoogleService();

        $options['singleEvents'] = true;
        $options['orderBy']      = 'startTime';

        if ($this->synchronizable->synchronization->token) {
            unset($options['timeMin'], $options['timeMax']);
            $options['syncToken'] = $this->synchronizable->synchronization->token;
        }

        Log::info('SynchronizeEvents: starting request', [
            'account_id' => $this->synchronizable->id ?? null,
            'options'    => $options,
            'tenant_db'  => $this->tenantDb,
        ]);

        return $service->events->listEvents($this->synchronizable->google_id, $options);
    }

    public function synchronize(): void
    {
        $service = $this->getGoogleService();
        $options = [
            'timeMin' => now()->subYears(1)->toAtomString(),
            'timeMax' => now()->addYears(2)->toAtomString(),
        ];

        $googleEvents = $this->getGoogleRequest($service, $options);

        do {
            $googleIds = collect($googleEvents->getItems())->pluck('id')->toArray();

            $deletedCount = $this->synchronizable->events()
                ->whereNotIn('google_id', $googleIds)
                ->delete();

            Log::info('SynchronizeEvents: deleted missing events', [
                'deleted_count' => $deletedCount,
                'account_id'    => $this->synchronizable->id,
                'tenant_db'     => $this->tenantDb,
            ]);

            foreach ($googleEvents->getItems() as $googleEvent) {
                $this->syncItem($googleEvent);
            }

            $pageToken = $googleEvents->getNextPageToken();
            if ($pageToken) {
                $options['pageToken'] = $pageToken;
                $googleEvents = $service->events->listEvents($this->synchronizable->google_id, $options);
            } else {
                $this->synchronizable->synchronization->token = $googleEvents->getNextSyncToken();
                $this->synchronizable->synchronization->last_synchronized_at = now();
                $this->synchronizable->synchronization->save();

                Log::info('SynchronizeEvents: saved nextSyncToken', [
                    'sync_token' => $this->synchronizable->synchronization->token,
                    'tenant_db'  => $this->tenantDb,
                ]);
            }
        } while ($pageToken);
    }

    public function syncItem($googleEvent)
    {
        $startDatetime = $this->parseDatetime($googleEvent->start);
        $endDatetime   = $this->parseDatetime($googleEvent->end);

        Log::info('SynchronizeEvents: processing item', [
            'google_id' => $googleEvent->id,
            'summary'   => $googleEvent->summary,
            'start'     => $startDatetime->toDateTimeString(),
            'end'       => $endDatetime->toDateTimeString(),
            'tenant_db' => $this->tenantDb,
        ]);

        if ($googleEvent->status === 'cancelled') {
            $this->synchronizable->events()->where('google_id', $googleEvent->id)->delete();
            return;
        }

        if ($startDatetime->isPast()) {
            return;
        }

        $event = $this->synchronizable->events()->updateOrCreate(
            ['google_id' => $googleEvent->id],
            []
        );

        $activity = $event->activity()->updateOrCreate(
            ['id' => $event->activity_id],
            [
                'title'         => $googleEvent->summary,
                'comment'       => $googleEvent->description ?? '',
                'schedule_from' => $startDatetime,
                'schedule_to'   => $endDatetime,
                'user_id'       => $this->synchronizable->account->user_id,
                'type'          => 'meeting',
            ]
        );

        $event->update(['activity_id' => $activity->id]);
    }

    public function dropAllSyncedItems()
    {
        $this->synchronizable->events()->delete();
    }

    protected function parseDatetime($googleDatetime)
    {
        $rawDatetime = $googleDatetime->dateTime ?? $googleDatetime->date;
        return Carbon::parse($rawDatetime);
    }
}
