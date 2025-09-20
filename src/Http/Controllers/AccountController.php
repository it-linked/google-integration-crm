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

class AccountController extends Controller
{
    public function __construct(
        protected Google $google,
        protected UserRepository $userRepository,
        protected AccountRepository $accountRepository,
        protected CalendarRepository $calendarRepository
    ) {}

    /**
     * Display Google account page
     */
    public function index(): View|RedirectResponse
    {
        $route = request('route', 'calendar');
        $account = $this->accountRepository->findOneByField('user_id', Auth::id());

        return view('google::'.$route.'.index', compact('account'));
    }

    /**
     * Handle OAuth flow and store token
     */
    public function store(): RedirectResponse
    {
        $route = request('route', 'calendar');
        $account = $this->accountRepository->findOneByField('user_id', Auth::id());

        // Step 1: If no code yet, redirect to Google OAuth consent
        if (! request()->has('code')) {
            session(['route' => $route]);

            // Boot Google client for current user (requires GoogleApp setup)
            $this->google->forCurrentUser();

            // Redirect to Google consent screen
            return redirect($this->google->createAuthUrl());
        }

        // Step 2: Exchange code for access token
        try {
            $this->google->forCurrentUser()->authenticate(request()->get('code'));

            $googleUser = $this->google->service('Oauth2')->userinfo->get();

            $token = $this->google->getAccessToken();

            // Step 3: Store or update account
            $this->userRepository->find(Auth::id())->accounts()->updateOrCreate(
                ['google_id' => $googleUser->id],
                [
                    'name'   => $googleUser->email,
                    'token'  => $token,
                    'scopes' => [$route],
                ]
            );

            session(['route' => $route]);

            // Step 4: Optionally start sync for calendar
            if ($route === 'calendar') {
                $account = $this->accountRepository->findOneByField('user_id', Auth::id());
                if ($account?->synchronization) {
                    $account->synchronization->ping();
                    $account->synchronization->startListeningForChanges();
                }
            }

            return redirect()->route('admin.google.index', ['route' => $route])
                             ->with('success', 'Google account connected successfully!');
        } catch (\Throwable $e) {
            Log::error('Google OAuth failed', ['message' => $e->getMessage()]);
            return redirect()->back()->withErrors([
                'error' => 'Failed to authenticate with Google: ' . $e->getMessage()
            ]);
        }
    }

    /**
     * Remove a Google account
     */
    public function destroy(int $id): RedirectResponse
    {
        $account = $this->accountRepository->findOrFail($id);

        if (count($account->scopes) > 1) {
            // Just remove the requested scope
            $scopes = array_values(array_diff($account->scopes, [request('route')]));
            $this->accountRepository->update(['scopes' => $scopes], $account->id);
        } else {
            // Delete entire account
            $account->calendars->each->delete();
            $this->accountRepository->destroy($id);

            // Revoke token from Google
            try {
                $this->google->forUser($account->user_id)->revokeToken($account->token);
            } catch (\Throwable $e) {
                // Ignore
            }
        }

        session()->flash('success', 'Google account removed successfully.');
        return redirect()->back();
    }
}
