<?php

namespace Webkul\Google\Listeners;

use Illuminate\Support\Facades\Log;
use Webkul\Activity\Contracts\Activity as ActivityContract;
use Webkul\Google\Repositories\AccountRepository;
use Webkul\Google\Repositories\CalendarRepository;
use Webkul\Google\Repositories\EventRepository;
use RuntimeException;

class Activity
{
    public function __construct(
        protected AccountRepository $accountRepository,
        protected CalendarRepository $calendarRepository,
        protected EventRepository $eventRepository
    ) {}

    /**
     * Handle the created event.
     */
    public function created(ActivityContract $activity)
    {
        Log::info('Google Sync: Activity created START', ['id' => $activity->id, 'type' => $activity->type]);

        if (! in_array($activity->type, ['call', 'meeting', 'lunch'])) {
            Log::info('Google Sync: Skipped (unsupported type)', ['type' => $activity->type]);
            return;
        }

        $account = $this->accountRepository->findOneByField('user_id', auth()->id());
        Log::info('Google Sync: Fetched Google account', ['found' => (bool) $account]);

        if (! $account) return;

        $calendar = $this->calendarRepository->findOneWhere([
            'google_account_id' => $account->id,
            'is_primary'        => 1,
        ]);
        Log::info('Google Sync: Fetched primary calendar', ['found' => (bool) $calendar]);

        if (! $calendar) return;

        try {
            $service = $calendar->getGoogleService('Calendar');
            $eventData = $this->prepareEventData($activity);

            Log::info('Google Sync: Creating Google event', ['data' => $eventData]);

            $googleEvent = $service->events->insert(
                $calendar->google_id,
                new \Google_Service_Calendar_Event($eventData)
            );

            $this->eventRepository->create([
                'activity_id'        => $activity->id,
                'google_id'          => $googleEvent->id,
                'google_calendar_id' => $calendar->id,
            ]);

            Log::info('Google Sync: Event created successfully', ['google_event_id' => $googleEvent->id]);
        } catch (\Throwable $e) {
            Log::error('Google Sync: Failed to create event', ['exception' => $e->getMessage()]);
        }
    }

    /**
     * Handle the updated event.
     */
    public function updated(ActivityContract $activity)
    {
        Log::info('Google Sync: Activity updated START', ['id' => $activity->id, 'type' => $activity->type]);

        if (! in_array($activity->type, ['call', 'meeting', 'lunch'])) {
            Log::info('Google Sync: Skipped (unsupported type)', ['type' => $activity->type]);
            return;
        }

        $account = $this->accountRepository->findOneByField('user_id', auth()->id());
        Log::info('Google Sync: Fetched Google account', ['found' => (bool) $account]);

        if (! $account) return;

        $event = $this->eventRepository->findOneByField('activity_id', $activity->id);
        Log::info('Google Sync: Existing event lookup', ['found' => (bool) $event]);

        $calendar = $event?->calendar ?? $this->calendarRepository->findOneWhere([
            'google_account_id' => $account->id,
            'is_primary'        => 1,
        ]);
        Log::info('Google Sync: Calendar resolved', ['found' => (bool) $calendar]);

        if (! $calendar) return;

        try {
            $service = $calendar->getGoogleService('Calendar');
            $eventData = $this->prepareEventData($activity);

            if ($event?->google_id) {
                Log::info('Google Sync: Updating Google event', ['google_event_id' => $event->google_id]);
                $googleEvent = $service->events->update(
                    $calendar->google_id,
                    $event->google_id,
                    new \Google_Service_Calendar_Event($eventData)
                );
            } else {
                Log::info('Google Sync: Creating new Google event (no previous ID)');
                $googleEvent = $service->events->insert(
                    $calendar->google_id,
                    new \Google_Service_Calendar_Event($eventData)
                );
            }

            $this->eventRepository->updateOrCreate(
                ['activity_id' => $activity->id],
                [
                    'google_id'          => $googleEvent->id,
                    'google_calendar_id' => $calendar->id,
                ]
            );

            Log::info('Google Sync: Event synced successfully', ['google_event_id' => $googleEvent->id]);
        } catch (\Throwable $e) {
            Log::error('Google Sync: Failed to update event', ['exception' => $e->getMessage()]);
        }
    }

    /**
     * Handle the deleted event.
     */
    public function deleted(int $id)
    {
        Log::info('Google Sync: Activity deleted START', ['activity_id' => $id]);

        $event = $this->eventRepository->findOneByField('activity_id', $id);
        Log::info('Google Sync: Event lookup', ['found' => (bool) $event]);

        if (! $event) return;

        try {
            $service = $event->calendar->getGoogleService('Calendar');
            $service->events->delete($event->calendar->google_id, $event->google_id);
            Log::info('Google Sync: Event deleted successfully', ['google_event_id' => $event->google_id]);
        } catch (\Throwable $e) {
            Log::error('Google Sync: Failed to delete event', ['exception' => $e->getMessage()]);
        }
    }

    /**
     * Prepare Google event data.
     */
    protected function prepareEventData(ActivityContract $activity): array
    {
        $eventData = [
            'summary'     => $activity->title,
            'description' => $activity->comment,
            'start'       => [
                'dateTime' => $activity->schedule_from->toAtomString(),
                'timeZone' => $activity->schedule_from->timezone->getName(),
            ],
            'end' => [
                'dateTime' => $activity->schedule_to->toAtomString(),
                'timeZone' => $activity->schedule_from->timezone->getName(),
            ],
            'attendees' => [],
        ];

        foreach ($activity->participants as $participant) {
            $email = $participant->user
                ? $participant->user->email
                : ($participant->person->emails[0]['value'] ?? null);

            $name = $participant->user
                ? $participant->user->name
                : $participant->person->name;

            if ($email) {
                $eventData['attendees'][] = ['email' => $email, 'display_name' => $name];
            }
        }

        return $eventData;
    }
}
