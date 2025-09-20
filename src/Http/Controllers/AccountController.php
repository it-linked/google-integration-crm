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
    /**
     * Create a new controller instance.
     *
     *
     * @return void
     */
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
        if (! request('route')) {
            return redirect()->route('admin.google.index', ['route' => 'calendar']);
        }

        $account = $this->accountRepository->findOneByField('user_id', auth()->user()->id);

        return view('google::' . request('route') . '.index', compact('account'));
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(): RedirectResponse
    {
        $route = request('route', 'calendar');
        $account = $this->accountRepository->findOneByField('user_id', auth()->user()->id);

        if ($account) {
            // Update scopes if already connected
            $this->accountRepository->update([
                'scopes' => array_unique(array_merge($account->scopes ?? [], [$route])),
            ], $account->id);

            if ($route === 'calendar') {
                $account->synchronization->ping();
                $account->synchronization->startListeningForChanges();
            }

            session()->put('route', $route);

            return redirect()->route('admin.google.index', ['route' => $route]);
        }

        // If no 'code' parameter, redirect to Google OAuth
        if (! request()->has('code')) {
            session()->put('route', $route);

            // 🔹 Boot Google client for current user before creating auth URL
            $this->google->forCurrentUser();

            return redirect($this->google->createAuthUrl());
        }

        // Exchange authorization code for access token
        $this->google->forCurrentUser()->authenticate(request()->get('code'));

        // Fetch user info from Google
        $googleUser = $this->google->service('Oauth2')->userinfo->get();

        // Store account
        $this->userRepository->find(auth()->user()->id)->accounts()->updateOrCreate(
            ['google_id' => $googleUser->id],
            [
                'name'   => $googleUser->email,
                'token'  => $this->google->getAccessToken(),
                'scopes' => [$route],
            ]
        );

        return redirect()->route('admin.google.index', ['route' => $route]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(int $id): RedirectResponse
    {
        $account = $this->accountRepository->findOrFail($id);
        $route = request('route');

        if (count($account->scopes) > 1) {
            // Remove just the requested route from scopes
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

            // 🔹 Safely boot Google client for the user before revoking token
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
