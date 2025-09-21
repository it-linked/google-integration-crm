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
    protected ?\Webkul\Google\Contracts\GoogleApp $googleApp = null;
    protected ?Google_Client $client = null;

    public function __construct(
        protected GoogleAppRepository $googleAppRepository
    ) {}

    /* -----------------------------------------------------------------
     |  Lazy initializers
     | -----------------------------------------------------------------
     */
    protected function initGoogleApp(): void
    {
        if ($this->googleApp) return;

        $this->googleApp = $this->googleAppRepository->first();

        if (! $this->googleApp) {
            throw new RuntimeException('Google App configuration not found. Please set it up first.');
        }
    }

    protected function initClient(): void
    {
        if ($this->client) return;

        $this->initGoogleApp();

        $client = new Google_Client;
        $client->setClientId($this->googleApp->client_id);
        $client->setClientSecret($this->googleApp->client_secret);
        $client->setRedirectUri($this->googleApp->redirect_uri);
        $client->setScopes($this->googleApp->scopes ?: []);

        // Optional defaults
        $client->setAccessType(config('services.google.access_type', 'offline'));
        $client->setApprovalPrompt(config('services.google.approval_prompt', 'force'));
        $client->setIncludeGrantedScopes(config('services.google.include_granted_scopes', true));

        $this->client = $client;
    }

    /* -----------------------------------------------------------------
     |  Public API
     | -----------------------------------------------------------------
     */

    public function __call($method, $args): mixed
    {
        $this->initClient();

        if (! method_exists($this->client, $method)) {
            throw new BadMethodCallException("Call to undefined method '{$method}'");
        }

        return $this->client->{$method}(...$args);
    }

    public function service(string $service): mixed
    {
        $this->initClient();

        $className = "Google_Service_{$service}";

        return new $className($this->client);
    }

    /**
     * Exchange authorization code for access token and set it.
     */
    public function authenticate(string $code): array
    {
        $this->initClient();

        $token = $this->client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            throw new RuntimeException('Google token exchange failed: ' . $token['error']);
        }

        $this->client->setAccessToken($token);

        return $token;
    }

    public function connectUsing(string|array $token): self
    {
        $this->initClient();
        $this->client->setAccessToken($token);

        return $this;
    }

    public function revokeToken(string|array|null $token = null): bool
    {
        $this->initClient();
        $token = $token ?? $this->client->getAccessToken();

        return $this->client->revokeToken($token);
    }

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

    public function client(): Google_Client
    {
        $this->initClient();
        return $this->client;
    }

    public function googleApp(): \Webkul\Google\Contracts\GoogleApp
    {
        $this->initGoogleApp();
        return $this->googleApp;
    }
}
