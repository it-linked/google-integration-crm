<?php

namespace Webkul\Google\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Webkul\Google\Repositories\AccountRepository;
use Webkul\Google\Repositories\CalendarRepository;
use Webkul\Google\Services\Google;
use Webkul\User\Repositories\UserRepository;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use RuntimeException;

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
        $route = request('route', 'calendar');
        $account = $this->accountRepository->findOneByField('user_id', Auth::id());

        return view('google::' . $route . '.index', compact('account'));
    }

    public function store(): RedirectResponse
    {
        $route = request('route', 'calendar');

        Log::info('Google Account store called', [
            'user_id' => auth()->id(),
            'route'   => $route,
            'has_code' => request()->has('code'),
        ]);

        $account = $this->accountRepository->findOneByField('user_id', auth()->id());
        Log::info('Existing account', ['account' => $account]);

        if ($account) {
            $scopes = array_unique(array_merge($account->scopes ?? [], [$route]));
            $this->accountRepository->update(['scopes' => $scopes], $account->id);

            if ($route === 'calendar') {
                try {
                    $account->synchronize();
                } catch (\Throwable $e) {
                    Log::error('Failed to synchronize calendars', ['message' => $e->getMessage()]);
                }
            }

            session()->put('route', $route);
            return redirect()->route('admin.google.index', ['route' => $route]);
        }

        // Step 1: Redirect to Google OAuth
        if (! request()->has('code')) {
            session()->put('route', $route);

            $authUrl = $this->google->forCurrentUser()->getClient()->createAuthUrl();
            Log::info('Redirecting to Google OAuth URL', ['auth_url' => $authUrl]);

            return redirect($authUrl);
        }

        // Step 2: Exchange code for token
        $token = $this->google->forCurrentUser()->getClient()->fetchAccessTokenWithAuthCode(request('code'));
        Log::info('Access token fetched', ['token' => $token]);

        $this->google->connectUsing($token);

        // Step 3: Fetch Google user info
        try {
            $googleUser = $this->google->service('Oauth2')->userinfo->get();
            Log::info('Google user info fetched', ['google_user' => $googleUser]);
        } catch (\Throwable $e) {
            Log::error('Failed to fetch Google user info', ['message' => $e->getMessage()]);
            return back()->withErrors(['error' => 'Failed to fetch Google user info.'])->withInput();
        }

        // Step 4: Save account
        try {
            $account = $this->userRepository->find(auth()->id())->accounts()->updateOrCreate(
                ['google_id' => $googleUser->id],
                [
                    'name'   => $googleUser->email,
                    'token'  => $token,
                    'scopes' => [$route],
                ]
            );

            if ($route === 'calendar') {
                $account->synchronize();
            }

            session()->put('route', $route);

        } catch (\Throwable $e) {
            Log::error('Failed to save Google account', ['message' => $e->getMessage()]);
            return back()->withErrors(['error' => 'Failed to save Google account.'])->withInput();
        }

        return redirect()->route('admin.google.index', ['route' => $route]);
    }

    public function destroy(int $id): RedirectResponse
    {
        $account = $this->accountRepository->findOrFail($id);
        $route = request('route', 'calendar');

        if (count($account->scopes) > 1) {
            $scopes = $account->scopes;
            if (($key = array_search($route, $scopes)) !== false) {
                unset($scopes[$key]);
            }

            $this->accountRepository->update(['scopes' => array_values($scopes)], $account->id);
        } else {
            $account->calendars->each->delete();
            $this->accountRepository->destroy($id);

            try {
                $this->google->forUser($account->user_id)->revokeToken($account->token);
            } catch (\Throwable $e) {
                // ignore
            }
        }

        session()->flash('success', trans('google::app.account-deleted'));
        return redirect()->back();
    }
}
