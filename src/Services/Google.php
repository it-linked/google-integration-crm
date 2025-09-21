<?php

namespace Webkul\Google\Services;

use Google_Client;
use Webkul\Google\Models\Account;
use Webkul\Google\Models\Calendar;
use Webkul\Google\Repositories\GoogleAppRepository;
use RuntimeException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

class Google
{
    /**
     * Google Client instance.
     */
    protected Google_Client $client;

    /**
     * Current GoogleApp configuration.
     */
    protected ?\Webkul\Google\Contracts\GoogleApp $googleApp = null;

    /**
     * Create a new service instance.
     */
    public function __construct(
        protected GoogleAppRepository $googleAppRepository
    ) {
        // Get the logged-in master admin
        $admin = Auth::guard('user')->user(); // adjust guard if different

        if ($admin) {
            // Assuming the first tenant is the active one
            $tenant = $admin->tenants()->first();

            if ($tenant && $tenant->tenant_db) {
                config(['database.connections.tenant.database' => $tenant->tenant_db]);

                DB::purge('tenant');
                DB::reconnect('tenant');
            }
        }

        // Now the repository will query the tenant DB
        $this->googleApp = $googleAppRepository->first();

        if (! $this->googleApp) {
            throw new \RuntimeException('Google App configuration not found.');
        }

        $client = new Google_Client;

        $client->setClientId($this->googleApp->client_id);
        $client->setClientSecret($this->googleApp->client_secret);
        $client->setRedirectUri($this->googleApp->redirect_uri);
        $client->setScopes($this->googleApp->scopes ?: []);

        // Optional defaults (you can also add columns in DB if needed)
        $client->setApprovalPrompt(config('services.google.approval_prompt', 'force'));
        $client->setAccessType(config('services.google.access_type', 'offline'));
        $client->setIncludeGrantedScopes(
            config('services.google.include_granted_scopes', true)
        );

        $this->client = $client;
    }

    /**
     * Dynamically call methods on the Google client.
     */
    public function __call($method, $args): mixed
    {
        if (! method_exists($this->client, $method)) {
            throw new \BadMethodCallException("Call to undefined method '{$method}'");
        }

        return $this->client->{$method}(...$args);
    }

    /**
     * Create a new Google service instance (e.g., Calendar, Oauth2).
     */
    public function service(string $service): mixed
    {
        $className = "Google_Service_{$service}";

        return new $className($this->client);
    }

    /**
     * Connect to Google using the given token.
     */
    public function connectUsing(string|array $token): self
    {
        $this->client->setAccessToken($token);

        return $this;
    }

    /**
     * Revoke a token.
     */
    public function revokeToken(string|array|null $token = null): bool
    {
        $token = $token ?? $this->client->getAccessToken();

        return $this->client->revokeToken($token);
    }

    /**
     * Connect using a synchronizable (Account or Calendar).
     */
    public function connectWithSynchronizable(mixed $synchronizable): self
    {
        $token = $this->getTokenFromSynchronizable($synchronizable);

        return $this->connectUsing($token);
    }

    /**
     * Extract the token from the synchronizable model.
     */
    protected function getTokenFromSynchronizable(mixed $synchronizable): mixed
    {
        return match (true) {
            $synchronizable instanceof Account  => $synchronizable->token,
            $synchronizable instanceof Calendar => $synchronizable->account->token,
            default => throw new RuntimeException('Invalid synchronizable type.'),
        };
    }

    /**
     * Access the underlying Google Client.
     */
    public function client(): Google_Client
    {
        return $this->client;
    }

    /**
     * Return the active GoogleApp record.
     */
    public function googleApp(): \Webkul\Google\Contracts\GoogleApp
    {
        return $this->googleApp;
    }
}
