<?php

namespace Webkul\Google\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Webkul\Google\Repositories\AccountRepository;
use Webkul\Google\Repositories\CalendarRepository;
use Webkul\Google\Services\Google;
use Webkul\User\Repositories\UserRepository;
use Illuminate\Support\Facades\Log;

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
        Log::info('AccountController@index loaded', [
            'user_id' => auth()->user()->id,
            'account_exists' => $account ? true : false
        ]);

        return view('google::'.request('route').'.index', compact('account'));
    }

    public function store(): RedirectResponse
    {
        $account = $this->accountRepository->findOneByField('user_id', auth()->user()->id);
        Log::info('AccountController@store called', [
            'user_id' => auth()->user()->id,
            'existing_account' => $account ? true : false,
            'route' => request('route'),
        ]);

        if ($account) {
            // Update scopes
            $this->accountRepository->update([
                'scopes' => array_merge($account->scopes ?? [], [request('route')]),
            ], $account->id);

            Log::info('Updated existing account scopes', [
                'account_id' => $account->id,
                'new_scopes' => $account->scopes
            ]);

            if (request('route') == 'calendar') {
                $account->synchronization->ping();
                $account->synchronization->startListeningForChanges();
                Log::info('Calendar synchronization started for account', ['account_id' => $account->id]);
            }

            session()->put('route', request('route'));
        } else {
            // No code -> redirect to Google OAuth
            if (! request()->has('code')) {
                session()->put('route', request('route'));
                $authUrl = $this->google->client()->createAuthUrl();
                Log::info('Redirecting user to Google OAuth', ['auth_url' => $authUrl]);
                return redirect($authUrl);
            }

            // Exchange code for token
            $token = $this->google->authenticate(request()->get('code'));
            Log::info('Token retrieved from Google', ['token' => $token]);

            // Retrieve user info
            $userInfo = $this->google->service('Oauth2')->userinfo->get();
            Log::info('User info retrieved from Google', [
                'google_id' => $userInfo->id,
                'email' => $userInfo->email
            ]);

            // Save account and token to DB
            $account = $this->userRepository->find(auth()->user()->id)->accounts()->updateOrCreate(
                [ 'google_id' => $userInfo->id ],
                [
                    'name'   => $userInfo->email,
                    'token'  => $token,
                    'scopes' => [session()->get('route', 'calendar')],
                ]
            );

            Log::info('Google account stored in DB', [
                'account_id' => $account->id,
                'token_saved' => $account->token ? true : false
            ]);

            // Connect and refresh if needed
            $this->google->connectWithSynchronizable($account);
            Log::info('Google client connected with account', ['account_id' => $account->id]);
        }

        return redirect()->route('admin.google.index', ['route' => session()->get('route', 'calendar')]);
    }

    public function destroy(int $id): RedirectResponse
    {
        $account = $this->accountRepository->findOrFail($id);
        Log::info('AccountController@destroy called', [
            'account_id' => $id,
            'scopes_count' => count($account->scopes)
        ]);

        if (count($account->scopes) > 1) {
            $scopes = $account->scopes;
            if (($key = array_search(request('route'), $scopes)) !== false) {
                unset($scopes[$key]);
            }

            $this->accountRepository->update([
                'scopes' => array_values($scopes),
            ], $account->id);

            Log::info('Removed scope from account', [
                'account_id' => $account->id,
                'remaining_scopes' => array_values($scopes)
            ]);
        } else {
            $account->calendars->each->delete();
            $this->accountRepository->destroy($id);
            $this->google->revokeToken($account->token);

            Log::info('Account and all calendars deleted, token revoked', ['account_id' => $id]);
        }

        session()->flash('success', trans('google::app.account-deleted'));
        return redirect()->back();
    }
}
