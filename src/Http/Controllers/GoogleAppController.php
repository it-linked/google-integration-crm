<?php

namespace Webkul\Google\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Webkul\Google\Repositories\GoogleAppRepository;
use Webkul\Google\Services\Google;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;

class GoogleAppController extends Controller
{
    public function __construct(
        protected GoogleAppRepository $googleAppRepository,
        protected Google $googleService
    ) {}

    /**
     * Display the configuration page.
     */
    public function index(): View
    {
        $googleApp = $this->googleAppRepository->findByUserId(Auth::id());

        return view('google::google.app.index', compact('googleApp'));
    }

    /**
     * Store or update the Google App configuration.
     */
    public function store(): RedirectResponse
    {
        $data = request()->validate([
            'client_id'     => 'required|string',
            'client_secret' => 'required|string',
            'redirect_uri'  => 'nullable|url',
            'webhook_uri'   => 'nullable|url',
            'scopes'        => 'nullable|string',
        ]);

        // Convert comma-separated string to array and trim
        $data['scopes'] = !empty($data['scopes'])
            ? array_map('trim', explode(',', $data['scopes']))
            : [];

        Log::info('Saving Google App', ['data' => $data, 'user_id' => Auth::id()]);

        try {
            $googleApp = $this->googleAppRepository->upsertForUser(Auth::id(), $data);

            Log::info('Google App saved', ['google_app_id' => $googleApp->id, 'user_id' => Auth::id()]);

            return redirect()
                ->route('admin.google.app.index')
                ->with('success', trans('google::app.index.configuration-saved'));
        } catch (\Throwable $e) {
            Log::error('Failed to save Google App', ['message' => $e->getMessage(), 'trace' => $e->getTraceAsString()]);

            return back()->withErrors([
                'error' => 'Failed to save Google App configuration: ' . $e->getMessage()
            ])->withInput();
        }
    }

    /**
     * Remove the Google App configuration for the current user.
     */
    public function destroy(): RedirectResponse
    {
        $googleApp = $this->googleAppRepository->findByUserId(Auth::id());

        if ($googleApp) {
            try {
                $this->googleService->forCurrentUser()->revokeToken();
            } catch (\Throwable $e) {
                // ignore revoke failures
            }

            $googleApp->delete();
        }

        return redirect()
            ->back()
            ->with('success', trans('google::app.index.configuration-deleted'));
    }
}
