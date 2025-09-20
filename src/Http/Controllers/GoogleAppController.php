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
    /**
     * Create a new controller instance.
     */
    public function __construct(
        protected GoogleAppRepository $googleAppRepository,
        protected Google $googleService
    ) {}

    /**
     * Display the configuration page.
     */
    public function index(): View
    {
        $userId    = Auth::id();
        $googleApp = $this->googleAppRepository->findByUserId($userId);

        return view('google::google.app.index', compact('googleApp'));
    }

    /**
     * Store or update the Google App configuration.
     */
    public function store(): RedirectResponse
    {
        $requestData = request()->all();
        Log::info('GoogleAppController@store called', [
            'request_data' => $requestData,
            'user_id' => Auth::id(),
        ]);

        $data = request()->validate([
            'client_id'     => 'required|string',
            'client_secret' => 'required|string',
            'redirect_uri'  => 'nullable|url',
            'webhook_uri'   => 'nullable|url',
            'scopes'        => 'nullable|string',
        ]);

        // Convert comma-separated string to array
        if (!empty($data['scopes'])) {
            $data['scopes'] = array_map('trim', explode(',', $data['scopes']));
        } else {
            $data['scopes'] = [];
        }

        Log::info('Validated data', $data);

        try {
            // Upsert Google App
            $googleApp = $this->googleAppRepository->upsertForUser(Auth::id(), $data);

            Log::info('GoogleApp upserted successfully', [
                'google_app_id' => $googleApp->id,
                'user_id' => Auth::id(),
            ]);

            return redirect()
                ->route('admin.google.app.index')
                ->with('success', trans('google::app.index.configuration-saved'));
        } catch (\Throwable $e) {
            // Log full exception details
            Log::error('GoogleAppController@store exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
                'user_id' => Auth::id(),
            ]);

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
            // 🔹 Revoke existing token if you want to fully disconnect
            try {
                $this->googleService
                    ->forCurrentUser()
                    ->revokeToken();
            } catch (\Throwable $e) {
                // Silently ignore revoke failures
            }

            $googleApp->delete();
        }

        return redirect()
            ->back()
            ->with('success', trans('google::app.index.configuration-deleted'));
    }
}
