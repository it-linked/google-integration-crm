<?php

namespace Webkul\Google\Services;

use Webkul\Google\Models\Account;
use Webkul\Google\Models\Calendar;
use Webkul\Google\Repositories\GoogleAppRepository;
use Illuminate\Support\Facades\Auth;
use RuntimeException;
use InvalidArgumentException;

class Google
{
    protected ?\Google_Client $client = null;
    protected GoogleAppRepository $googleAppRepository;

    public function __construct(GoogleAppRepository $googleAppRepository)
    {
        $this->googleAppRepository = $googleAppRepository;
    }

    /**
     * Dynamically call methods on the Google client
     */
    public function __call($method, $args): mixed
    {
        if (! $this->client || ! method_exists($this->client, $method)) {
            throw new RuntimeException("Google client is not booted or method [{$method}] does not exist.");
        }

        return $this->client->{$method}(...$args);
    }

    /**
     * Boot client for a specific user
     */
    public function forUser(int $userId): self
    {
        $googleApp = $this->googleAppRepository->findByUserId($userId);

        if (! $googleApp) {
            throw new RuntimeException("Google App credentials not configured for user ID {$userId}.");
        }

        $client = new \Google_Client();
        $client->setClientId($googleApp->client_id);
        $client->setClientSecret($googleApp->client_secret);
        $client->setRedirectUri($googleApp->redirect_uri);

        // Map friendly names to Google scopes
        $scopeMap = [
            'calendar' => 'https://www.googleapis.com/auth/calendar',
            'meet'     => 'https://www.googleapis.com/auth/calendar.events',
        ];

        $scopes = $googleApp->scopes ?? ['calendar'];
        $scopes = array_map(fn($s) => $scopeMap[$s] ?? $s, $scopes);
        $client->setScopes($scopes);
        $client->setAccessType('offline');
        $client->setApprovalPrompt('force');
        $client->setIncludeGrantedScopes(true);

        $this->client = $client;

        return $this;
    }

    /**
     * Boot client for current authenticated user
     */
    public function forCurrentUser(): self
    {
        if (! Auth::check()) {
            throw new RuntimeException('No authenticated user.');
        }

        return $this->forUser(Auth::id());
    }

    /**
     * Connect client using stored token
     */
    public function connectUsing(array|string $token): self
    {
        if (! $this->client) {
            throw new RuntimeException('Google client not booted.');
        }

        $this->client->setAccessToken($token);

        // Auto-refresh if expired and refresh token exists
        if ($this->client->isAccessTokenExpired() && $this->client->getRefreshToken()) {
            $newToken = $this->client->fetchAccessTokenWithRefreshToken($this->client->getRefreshToken());
            $this->client->setAccessToken($newToken);
        }

        return $this;
    }

    /**
     * Revoke a token
     */
    public function revokeToken(array|string|null $token = null): bool
    {
        if (! $this->client) {
            throw new RuntimeException('Google client not booted.');
        }

        $token = $token ?? $this->client->getAccessToken();
        return $this->client->revokeToken($token);
    }

    /**
     * Create a Google service instance (e.g., Oauth2, Calendar)
     */
    public function service(string $service): mixed
    {
        if (! $this->client) {
            throw new RuntimeException('Google client not booted.');
        }

        $className = "Google_Service_{$service}";
        if (! class_exists($className)) {
            throw new InvalidArgumentException("Google service [{$service}] does not exist.");
        }

        return new $className($this->client);
    }

    /**
     * Connect using an Account or Calendar model
     */
    public function connectWithSynchronizable(mixed $model): self
    {
        return $this->connectUsing($this->getTokenFromSynchronizable($model));
    }

    /**
     * Get token from an Account or Calendar
     */
    protected function getTokenFromSynchronizable(mixed $model): mixed
    {
        return match (true) {
            $model instanceof Account  => $model->token,
            $model instanceof Calendar => $model->account->token,
            default => throw new InvalidArgumentException('Invalid synchronizable model.'),
        };
    }

    /**
     * Get underlying Google_Client
     */
    public function getClient(): \Google_Client
    {
        if (! $this->client) {
            throw new RuntimeException('Google client not booted.');
        }

        return $this->client;
    }
}
