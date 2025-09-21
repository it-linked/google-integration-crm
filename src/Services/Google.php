<?php

namespace Webkul\Google\Services;

use Google_Client;
use Webkul\Google\Models\Account;
use Webkul\Google\Models\Calendar;
use Webkul\Google\Repositories\GoogleAppRepository;
use RuntimeException;
use BadMethodCallException;

class Google
{
    /**
     * Tenant Google App configuration (lazy-loaded).
     */
    protected ?\Webkul\Google\Contracts\GoogleApp $googleApp = null;

    /**
     * Google Client instance (lazy-loaded).
     */
    protected ?Google_Client $client = null;

    public function __construct(
        protected GoogleAppRepository $googleAppRepository
    ) {
        // nothing here triggers a DB query
    }

    /* -----------------------------------------------------------------
     |  Lazy initializers
     | -----------------------------------------------------------------
     */

    protected function initGoogleApp(): void
    {
        if ($this->googleApp) {
            return;
        }

        $this->googleApp = $this->googleAppRepository->first();

        if (! $this->googleApp) {
            throw new RuntimeException(
                'Google App configuration not found. Please set it up first.'
            );
        }
    }

    protected function initClient(): void
    {
        if ($this->client) {
            return;
        }

        $this->initGoogleApp();

        $client = new Google_Client;
        $client->setClientId($this->googleApp->client_id);
        $client->setClientSecret($this->googleApp->client_secret);
        $client->setRedirectUri($this->googleApp->redirect_uri);
        $client->setScopes($this->googleApp->scopes ?: []);

        // Optional defaults
        $client->setApprovalPrompt(config('services.google.approval_prompt', 'force'));
        $client->setAccessType(config('services.google.access_type', 'offline'));
        $client->setIncludeGrantedScopes(
            config('services.google.include_granted_scopes', true)
        );

        $this->client = $client;
    }

    /* -----------------------------------------------------------------
     |  Public API
     | -----------------------------------------------------------------
     */

    /**
     * Dynamically call methods on the Google client.
     */
    public function __call($method, $args): mixed
    {
        $this->initClient();

        if (! method_exists($this->client, $method)) {
            throw new BadMethodCallException("Call to undefined method '{$method}'");
        }

        return $this->client->{$method}(...$args);
    }

    /**
     * Create a new Google service instance (e.g., Calendar, Oauth2).
     */
    public function service(string $service): mixed
    {
        $this->initClient();

        $className = "Google_Service_{$service}";

        return new $className($this->client);
    }

    /**
     * Connect to Google using the given token.
     */
    public function connectUsing(string|array $token): self
    {
        $this->initClient();
        $this->client->setAccessToken($token);

        return $this;
    }

    /**
     * Revoke a token.
     */
    public function revokeToken(string|array|null $token = null): bool
    {
        $this->initClient();
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
        $this->initClient();

        return $this->client;
    }

    /**
     * Return the active GoogleApp record.
     */
    public function googleApp(): \Webkul\Google\Contracts\GoogleApp
    {
        $this->initGoogleApp();

        return $this->googleApp;
    }
}
