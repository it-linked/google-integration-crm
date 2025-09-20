<?php

namespace Webkul\Google\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Webkul\Google\Repositories\AccountRepository;
use Webkul\Google\Repositories\CalendarRepository;
use Webkul\Google\Services\Google;
use Webkul\User\Repositories\UserRepository;
use RuntimeException;

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

        $account = $this->accountRepository->findOneByField('user_id', auth()->user()->id);

        return view('google::' . $route . '.index', compact('account'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(): RedirectResponse
    {
        $route = request('route', 'calendar');

        // Check if account already exists
        $account = $this->accountRepository->findOneByField('user_id', auth()->user()->id);

        if ($account) {
            // Update scopes
            $this->accountRepository->update([
                'scopes' => array_unique(array_merge($account->scopes ?? [], [$route])),
            ], $account->id);

            // Ensure synchronization record exists
            if (! $account->synchronization) {
                $account->synchronization()->create([
                    'last_synced_at' => now(),
                    'status'         => 'pending',
                ]);
            }

            // Optionally start syncing
            if ($route === 'calendar') {
                $account->synchronization->ping();
                $account->synchronization->startListeningForChanges();
            }

            session()->put('route', $route);

            return redirect()->route('admin.google.index', ['route' => $route]);
        }

        // If no OAuth code, redirect to Google
        if (! request()->has('code')) {
            session()->put('route', $route);

            $authUrl = $this->google
                ->forCurrentUser()
                ->createAuthUrl();

            return redirect($authUrl);
        }

        // Exchange code for access token
        $token = $this->google
            ->forCurrentUser()
            ->getClient()
            ->fetchAccessTokenWithAuthCode(request('code'));

        // Attach token to client
        $this->google->connectUsing($token);

        // Fetch user info from Google
        $googleUser = $this->google->service('Oauth2')->userinfo->get();

        // Store account with full token (including refresh_token)
        $account = $this->userRepository->find(auth()->user()->id)->accounts()->updateOrCreate(
            ['google_id' => $googleUser->id],
            [
                'name'   => $googleUser->email,
                'token'  => $token,
                'scopes' => [$route],
            ]
        );

        // Create initial synchronization record if none exists
        if (! $account->synchronization) {
            $account->synchronization()->create([
                'last_synced_at' => now(),
                'status'         => 'pending',
            ]);
        }

        session()->put('route', $route);

        return redirect()->route('admin.google.index', ['route' => $route]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): RedirectResponse
    {
        $account = $this->accountRepository->findOrFail($id);
        $route = request('route', 'calendar');

        if (count($account->scopes) > 1) {
            // Remove only the requested route from scopes
            $scopes = $account->scopes;
            if (($key = array_search($route, $scopes)) !== false) {
                unset($scopes[$key]);
            }

            $this->accountRepository->update([
                'scopes' => array_values($scopes),
            ], $account->id);
        } else {
            // Delete all calendars and account
            $account->calendars->each->delete();
            $this->accountRepository->destroy($id);

            // Safely revoke token
            try {
                $this->google->forUser($account->user_id)->revokeToken($account->token);
            } catch (\Throwable $e) {
                // Ignore errors during revoke
            }
        }

        session()->flash('success', trans('google::app.account-deleted'));

        return redirect()->back();
    }
}
