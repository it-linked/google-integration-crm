<?php

namespace Webkul\Google\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Routing\Controller;
use Webkul\Google\Repositories\AccountRepository;
use Webkul\Google\Services\Google;
use Illuminate\Support\Facades\Log;

class CalendarController extends Controller
{
    public function __construct(
        protected AccountRepository $accountRepository,
        protected Google $google
    ) {}

    /**
     * Synchronize the selected calendar for the given account.
     */
    public function sync(int $id): RedirectResponse
    {
        $account = $this->accountRepository->findOrFail($id);
        Log::info('CalendarController@sync called', ['account_id' => $account->id, 'user_id' => auth()->id()]);

        $primaryCalendar = null;

        // Mark the selected calendar as primary
        foreach ($account->calendars as $calendar) {
            if ($calendar->id == request('calendar_id')) {
                $calendar->update(['is_primary' => 1]);
                $primaryCalendar = $calendar;
            } else {
                $calendar->update(['is_primary' => 0]);
            }
        }

        if (! $primaryCalendar) {
            Log::warning('No calendar matched the provided ID', ['calendar_id' => request('calendar_id')]);
            session()->flash('error', trans('google::app.calendar.index.no-calendar-selected'));
            return redirect()->back();
        }

        try {
            // Connect the Google client using the account
            $this->google->connectWithSynchronizable($account);

            // Auto-refresh token if expired
            $this->google->refreshIfExpired($account);

            // Trigger synchronization
            $primaryCalendar->synchronization->ping();
            $primaryCalendar->synchronization->startListeningForChanges();

            Log::info('Calendar synchronized successfully', [
                'account_id' => $account->id,
                'calendar_id' => $primaryCalendar->id
            ]);

            session()->flash('success', trans('google::app.account-synced'));
        } catch (\Throwable $e) {
            Log::error('Failed to sync calendar', [
                'account_id' => $account->id,
                'calendar_id' => $primaryCalendar->id,
                'error' => $e->getMessage()
            ]);

            session()->flash('error', trans('google::app.calendar.index.sync-failed'));
        }

        return redirect()->back();
    }
}
