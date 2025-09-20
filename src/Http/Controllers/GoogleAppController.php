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
        // Log incoming request
        Log::info('GoogleAppController@store called', [
            'request_data' => request()->all(),
            'user_id' => Auth::id(),
        ]);

        $data = request()->validate([
            'client_id'     => 'required|string',
            'client_secret' => 'required|string',
            'redirect_uri'  => 'nullable|url',
            'webhook_uri'   => 'nullable|url',
            'scopes'        => 'nullable|string', // change to string
        ]);

        // Convert scopes string to array
        if (!empty($data['scopes'])) {
            $data['scopes'] = array_map('trim', explode(',', $data['scopes']));
        } else {
            $data['scopes'] = [];
        }

        // Log validated data
        Log::info('Validated data', $data);

        try {
            $result = $this->googleAppRepository->upsertForUser(Auth::id(), $data);

            // Log repository result
            Log::info('Upsert result', ['result' => $result->toArray() ?? $result]);
        } catch (\Throwable $e) {
            // Log any exception
            Log::error('GoogleAppController@store exception', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return back()->withErrors(['error' => $e->getMessage()]);
        }

        return redirect()
            ->route('admin.google.app.index')
            ->with('success', trans('google::app.index.configuration-saved'));
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
