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

    public function index(): View|RedirectResponse
    {
        $route = request('route', 'calendar');
        $account = $this->accountRepository->findOneByField('user_id', Auth::id());

        return view('google::' . $route . '.index', compact('account'));
    }

    public function store(): RedirectResponse
    {
        $route = request('route', 'calendar');
        Log::info('Google Account store called', ['user_id' => Auth::id(), 'route' => $route, 'has_code' => request()->has('code')]);

        $account = $this->accountRepository->findOneByField('user_id', Auth::id());
        Log::info('Existing account', ['account' => $account]);

        if (! request()->has('code')) {
            session()->put('route', $route);

            try {
                $authUrl = $this->google->forCurrentUser()->getClient()->createAuthUrl();
                Log::info('Redirecting to Google OAuth URL', ['auth_url' => $authUrl]);
            } catch (\Throwable $e) {
                Log::error('Google App not configured', ['message' => $e->getMessage()]);
                return redirect()->route('admin.google.app.index')
                                 ->withErrors('Please configure your Google App credentials first.');
            }

            return redirect($authUrl);
        }

        // Exchange code for token
        try {
            $googleClient = $this->google->forCurrentUser()->getClient();
            $token = $googleClient->fetchAccessTokenWithAuthCode(request('code'));

            if (isset($token['error'])) {
                Log::error('Error fetching access token', ['token_error' => $token]);
                return redirect()->route('admin.google.index', ['route' => $route])
                                 ->withErrors('Google OAuth Error: ' . $token['error_description'] ?? $token['error']);
            }

            $this->google->connectUsing($token);
            Log::info('Access token fetched successfully', ['token' => $token]);
        } catch (\Throwable $e) {
            Log::error('Exception during token fetch', ['message' => $e->getMessage()]);
            return redirect()->route('admin.google.index', ['route' => $route])
                             ->withErrors('Failed to fetch Google token: ' . $e->getMessage());
        }

        // Fetch Google user info
        try {
            $client = $this->google->getClient();
            $userinfoScopes = [
                'https://www.googleapis.com/auth/userinfo.email',
                'https://www.googleapis.com/auth/userinfo.profile',
            ];

            $missingScopes = array_diff($userinfoScopes, $client->getScopes() ?? []);
            if (!empty($missingScopes)) {
                $client->addScope($missingScopes);
            }

            $googleUser = $this->google->service('Oauth2')->userinfo->get();
            Log::info('Google user info fetched', ['google_user' => $googleUser]);
        } catch (\Throwable $e) {
            Log::error('Failed to fetch Google user info', ['message' => $e->getMessage()]);
            return redirect()->route('admin.google.index', ['route' => $route])
                             ->withErrors('Failed to fetch Google user info: ' . $e->getMessage());
        }

        // Save or update account
        try {
            $account = $this->userRepository->find(Auth::id())->accounts()->updateOrCreate(
                ['google_id' => $googleUser->id],
                [
                    'name'   => $googleUser->email,
                    'token'  => $token,
                    'scopes' => [$route],
                ]
            );
            Log::info('Google account saved', ['account_id' => $account->id]);
        } catch (\Throwable $e) {
            Log::error('Failed to save Google account', ['message' => $e->getMessage()]);
            return redirect()->route('admin.google.index', ['route' => $route])
                             ->withErrors('Failed to save Google account: ' . $e->getMessage());
        }

        // Create initial synchronization
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
