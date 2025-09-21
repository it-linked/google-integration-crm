<?php

namespace Webkul\Google\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Webkul\Google\Repositories\AccountRepository;
use Webkul\Google\Repositories\CalendarRepository;
use Webkul\Google\Services\Google;
use Webkul\User\Repositories\UserRepository;

class AccountController extends Controller
{
    public function __construct(
        protected Google $google,
        protected UserRepository $userRepository,
        protected AccountRepository $accountRepository,
        protected CalendarRepository $calendarRepository
    ) {}

    public function index(): View|RedirectResponse
    {
        if (! request('route')) {
            return redirect()->route('admin.google.index', ['route' => 'calendar']);
        }

        $account = $this->accountRepository->findOneByField('user_id', auth()->user()->id);
        return view('google::'.request('route').'.index', compact('account'));
    }

    public function store(): RedirectResponse
    {
        $account = $this->accountRepository->findOneByField('user_id', auth()->user()->id);

        if ($account) {
            $this->accountRepository->update([
                'scopes' => array_merge($account->scopes ?? [], [request('route')]),
            ], $account->id);

            if (request('route') == 'calendar') {
                $account->synchronization->ping();
                $account->synchronization->startListeningForChanges();
            }

            session()->put('route', request('route'));
        } else {
            if (! request()->has('code')) {
                session()->put('route', request('route'));
                return redirect($this->google->client()->createAuthUrl());
            }

            $token = $this->google->authenticate(request()->get('code'));

            $userInfo = $this->google->service('Oauth2')->userinfo->get();

            $account = $this->userRepository->find(auth()->user()->id)->accounts()->updateOrCreate(
                [ 'google_id' => $userInfo->id ],
                [
                    'name'   => $userInfo->email,
                    'token'  => $token,
                    'scopes' => [session()->get('route', 'calendar')],
                ]
            );

            // ✅ Connect and auto-refresh token if needed
            $this->google->connectWithSynchronizable($account);
        }

        return redirect()->route('admin.google.index', ['route' => session()->get('route', 'calendar')]);
    }

    public function destroy(int $id): RedirectResponse
    {
        $account = $this->accountRepository->findOrFail($id);

        if (count($account->scopes) > 1) {
            $scopes = $account->scopes;
            if (($key = array_search(request('route'), $scopes)) !== false) {
                unset($scopes[$key]);
            }

            $this->accountRepository->update([
                'scopes' => array_values($scopes),
            ], $account->id);
        } else {
            $account->calendars->each->delete();
            $this->accountRepository->destroy($id);
            $this->google->revokeToken($account->token);
        }

        session()->flash('success', trans('google::app.account-deleted'));
        return redirect()->back();
    }
}
