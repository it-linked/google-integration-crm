<?php

namespace Webkul\Google\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Webkul\Google\Repositories\AccountRepository;
use Webkul\Google\Repositories\CalendarRepository;
use Webkul\Google\Services\Google;
use Webkul\User\Repositories\UserRepository;
use RuntimeException;
use Illuminate\Support\Facades\Auth;

class AccountController extends Controller
{
    public function __construct(
        protected Google $google,
        protected UserRepository $userRepository,
        protected AccountRepository $accountRepository,
        protected CalendarRepository $calendarRepository
    ) {}

    /**
     * Display a listing of the resource.
     */
    public function index(): View|RedirectResponse
    {
        $route = request('route', 'calendar');

        $account = $this->accountRepository->findOneByField('user_id', Auth::id());

        return view('google::' . $route . '.index', compact('account'));
    }

    /**
     * Store or update Google account connection.
     */
    public function store(): RedirectResponse
    {
        $route = request('route', 'calendar');

        // Check for existing account
        $account = $this->accountRepository->findOneByField('user_id', Auth::id());

        // Step 1: If no OAuth code, redirect to Google consent
        if (! request()->has('code')) {
            session()->put('route', $route);

            try {
                $authUrl = $this->google
                    ->forCurrentUser()
                    ->getClient()
                    ->createAuthUrl();
            } catch (RuntimeException $e) {
                return redirect()->route('admin.google.app.index')
                                 ->withErrors('Please configure your Google App credentials first.');
            }

            return redirect($authUrl);
        }

        // Step 2: Exchange code for access token
        try {
            $googleClient = $this->google->forCurrentUser()->getClient();

            $token = $googleClient->fetchAccessTokenWithAuthCode(request('code'));

            if (isset($token['error'])) {
                return redirect()->route('admin.google.index', ['route' => $route])
                                 ->withErrors('Google OAuth Error: ' . $token['error_description']);
            }

            $this->google->connectUsing($token);

        } catch (\Throwable $e) {
            return redirect()->route('admin.google.index', ['route' => $route])
                             ->withErrors('Failed to fetch Google token: ' . $e->getMessage());
        }

        // Step 3: Fetch user info from Google
        try {
            $googleUser = $this->google->service('Oauth2')->userinfo->get();
        } catch (\Throwable $e) {
            return redirect()->route('admin.google.index', ['route' => $route])
                             ->withErrors('Failed to fetch Google user info: ' . $e->getMessage());
        }

        // Step 4: Create or update account
        $account = $this->userRepository->find(Auth::id())->accounts()->updateOrCreate(
            ['google_id' => $googleUser->id],
            [
                'name'   => $googleUser->email,
                'token'  => $token,
                'scopes' => [$route],
            ]
        );

        // Step 5: Create initial synchronization if not exists
        if (! $account->synchronization) {
            $account->synchronization()->create([
                'last_synced_at' => now(),
                'status'         => 'pending',
            ]);
        }

        // Optional: start syncing for calendar
        if ($route === 'calendar') {
            $account->synchronization->ping();
            $account->synchronization->startListeningForChanges();
        }

        session()->put('route', $route);

        return redirect()->route('admin.google.index', ['route' => $route])
                         ->with('success', 'Google account connected successfully.');
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): RedirectResponse
    {
        $account = $this->accountRepository->findOrFail($id);
        $route = request('route', 'calendar');

        if (count($account->scopes) > 1) {
            // Remove only the requested scope
            $scopes = $account->scopes;
            if (($key = array_search($route, $scopes)) !== false) {
                unset($scopes[$key]);
            }

            $this->accountRepository->update(['scopes' => array_values($scopes)], $account->id);
        } else {
            // Delete all calendars and account
            $account->calendars->each->delete();
            $this->accountRepository->destroy($id);

            // Safely revoke token
            try {
                $this->google->forUser($account->user_id)->revokeToken($account->token);
            } catch (\Throwable $e) {
                // ignore errors
            }
        }

        session()->flash('success', trans('google::app.account-deleted'));

        return redirect()->back();
    }
}
