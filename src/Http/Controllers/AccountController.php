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

        Log::info('Google Account store called', [
            'user_id' => Auth::id(),
            'route' => $route,
            'has_code' => request()->has('code')
        ]);

        // Check for existing account
        $account = $this->accountRepository->findOneByField('user_id', Auth::id());
        Log::info('Existing account', ['account' => $account]);

        // Step 1: If no OAuth code, redirect to Google consent
        if (! request()->has('code')) {
            session()->put('route', $route);

            try {
                $authUrl = $this->google
                    ->forCurrentUser()
                    ->getClient()
                    ->createAuthUrl();

                Log::info('Redirecting to Google OAuth URL', ['auth_url' => $authUrl]);
            } catch (\Throwable $e) {
                Log::error('Google App not configured or client error', ['message' => $e->getMessage()]);

                return redirect()->route('admin.google.app.index')
                    ->withErrors('Please configure your Google App credentials first.');
            }

            return redirect($authUrl);
        }

        // Step 2: Exchange code for access token
        try {
            $googleClient = $this->google->forCurrentUser()->getClient();

            Log::info('Fetching access token with auth code', ['code' => request('code')]);

            $token = $googleClient->fetchAccessTokenWithAuthCode(request('code'));

            if (isset($token['error'])) {
                Log::error('Error fetching access token', ['token_error' => $token]);
                return redirect()->route('admin.google.index', ['route' => $route])
                    ->withErrors('Google OAuth Error: ' . $token['error_description'] ?? $token['error']);
            }

            $this->google->connectUsing($token);
            Log::info('Access token fetched successfully', ['token' => $token]);
        } catch (\Throwable $e) {
            Log::error('Exception during token fetch', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return redirect()->route('admin.google.index', ['route' => $route])
                ->withErrors('Failed to fetch Google token: ' . $e->getMessage());
        }

        // Step 3: Fetch user info from Google
        try {
            $googleUser = $this->google->service('Oauth2')->userinfo->get();
            Log::info('Google user info fetched', ['google_user' => $googleUser]);
        } catch (\Throwable $e) {
            Log::error('Failed to fetch Google user info', ['message' => $e->getMessage()]);
            return redirect()->route('admin.google.index', ['route' => $route])
                ->withErrors('Failed to fetch Google user info: ' . $e->getMessage());
        }

        // Step 4: Create or update account
        try {
            $account = $this->userRepository->find(Auth::id())->accounts()->updateOrCreate(
                ['google_id' => $googleUser->id],
                [
                    'name'   => $googleUser->email,
                    'token'  => $token,
                    'scopes' => [$route],
                ]
            );

            Log::info('Google account saved/updated', ['account_id' => $account->id]);
        } catch (\Throwable $e) {
            Log::error('Failed to save Google account', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);
            return redirect()->route('admin.google.index', ['route' => $route])
                ->withErrors('Failed to save Google account: ' . $e->getMessage());
        }

        // Step 5: Create initial synchronization if not exists
        try {
            if (! $account->synchronization) {
                $account->synchronization()->create([
                    'last_synced_at' => now(),
                    'status'         => 'pending',
                ]);

                Log::info('Google synchronization created', ['account_id' => $account->id]);
            }

            if ($route === 'calendar') {
                $account->synchronization->ping();
                $account->synchronization->startListeningForChanges();
                Log::info('Started calendar sync', ['account_id' => $account->id]);
            }
        } catch (\Throwable $e) {
            Log::error('Failed during synchronization setup', ['message' => $e->getMessage()]);
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
