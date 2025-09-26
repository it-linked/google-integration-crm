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

        $primaryCalendar = null;

        foreach ($account->calendars as $calendar) {
            if ($calendar->id == request('calendar_id')) {
                $calendar->update(['is_primary' => 1]);
                $primaryCalendar = $calendar;
            } else {
                $calendar->update(['is_primary' => 0]);
            }
        }

        if (! $primaryCalendar) {
            session()->flash('error', trans('google::app.calendar.index.no-calendar-selected'));
            return redirect()->back();
        }

        try {
            $this->google->connectWithSynchronizable($account);
            $this->google->refreshIfExpired($account);

            $primaryCalendar->synchronization->ping();
            $primaryCalendar->synchronization->startListeningForChanges();

            Log::info('Google calendar synced', [
                'account_id'  => $account->id,
                'calendar_id' => $primaryCalendar->id
            ]);

            session()->flash('success', trans('google::app.account-synced'));
        } catch (\Throwable $e) {
            Log::error('Google calendar sync failed', [
                'account_id'  => $account->id,
                'calendar_id' => $primaryCalendar->id,
                'error'       => $e->getMessage()
            ]);

            session()->flash('error', trans('google::app.calendar.index.sync-failed'));
        }

        return redirect()->back();
    }
}
