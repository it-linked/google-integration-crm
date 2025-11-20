<?php

namespace Webkul\Google\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Http\RedirectResponse;
use Illuminate\View\View;
use Webkul\Google\Repositories\GoogleAppRepository;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\RedirectResponse;

class GoogleAppController extends Controller
{
    /**
     * Create a new controller instance.
     */
    public function __construct(
        protected GoogleAppRepository $googleAppRepository
    ) {}

    /**
     * Show the Google App configuration page.
     */
    public function index(): View
    {
        // Assuming one record per tenant (or globally)
        $googleApp = $this->googleAppRepository->first();

        return view('google::google.app.index', compact('googleApp'));
    }

    /**
     * Create or update the Google App record.
     */
    public function store(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'client_id'     => 'required|string',
            'client_secret' => 'required|string',
            'redirect_uri'  => 'nullable|url',
            'webhook_uri'   => 'nullable|url',
            'scopes'        => 'nullable|string', // comma-separated in form
        ]);

        // Convert comma separated scopes to array
        $validated['scopes'] = ! empty($validated['scopes'])
            ? array_map('trim', explode(',', $validated['scopes']))
            : [];

        $existing = $this->googleAppRepository->first();

        if ($existing) {
            $this->googleAppRepository->update($validated, $existing->id);
            session()->flash('success', trans('google::app.index.configuration-saved'));
        } else {
            $this->googleAppRepository->create($validated);
            session()->flash('success', trans('google::app.index.configuration-saved'));
        }

        return redirect()->route('admin.google.app.index');
    }

    /**
     * Remove the Google App configuration.
     */
    public function destroy(Request $request): RedirectResponse
{
    $id = $request->route('id') ?? $request->input('id');

    try {
        $googleApp = $this->googleAppRepository->find($id);

        if ($googleApp) {
            $this->googleAppRepository->delete($googleApp->id);
            session()->flash('success', trans('google::app.index.configuration-deleted'));

            Log::info("Google App deleted successfully.", ['id' => $id]);
        } else {
            session()->flash('error', trans('google::app.index.configuration-deleted'));
            Log::warning("Google App deletion failed. Record not found.", ['id' => $id]);
        }
    } catch (\Exception $e) {
        Log::error("Error deleting Google App.", [
            'id' => $id,
            'message' => $e->getMessage(),
            'trace' => $e->getTraceAsString(),
        ]);

        session()->flash('error', 'An error occurred while deleting the configuration.');
    }

    return redirect()->route('admin.google.app.index');
}
