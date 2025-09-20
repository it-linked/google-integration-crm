<?php

namespace Webkul\Google\Services;

use Webkul\Google\Models\Account;
use Webkul\Google\Models\Calendar;
use Webkul\Google\Repositories\GoogleAppRepository;
use Illuminate\Support\Facades\Auth;
use InvalidArgumentException;
use RuntimeException;

class Google
{
    /**
     * The underlying Google Client.
     *
     * @var \Google_Client|null
     */
    protected ?\Google_Client $client = null;

    /**
     * GoogleApp repository instance.
     *
     * @var \Webkul\Google\Repositories\GoogleAppRepository
     */
    protected GoogleAppRepository $googleAppRepository;

    /**
     * Create a new Google service instance.
     *
     * @param  \Webkul\Google\Repositories\GoogleAppRepository  $googleAppRepository
     */
    public function __construct(GoogleAppRepository $googleAppRepository)
    {
        $this->googleAppRepository = $googleAppRepository;
        // ❗ No automatic booting here – call forUser() explicitly
    }

    /**
     * Dynamically forward method calls to the underlying Google client.
     */
    public function __call($method, $args): mixed
    {
        if (! $this->client || ! method_exists($this->client, $method)) {
            throw new RuntimeException("Google client is not booted or method [{$method}] does not exist.");
        }

        return $this->client->{$method}(...$args);
    }

    /**
     * Build the client for a specific user.
     *
     * @param  int  $userId
     * @return $this
     */
    public function forUser(int $userId): self
    {
        $this->bootClientForUser($userId);

        return $this;
    }

    /**
     * Build the client for the currently authenticated user.
     *
     * @return $this
     */
    public function forCurrentUser(): self
    {
        if (! Auth::check()) {
            throw new RuntimeException('No authenticated user to boot Google client for.');
        }

        return $this->forUser(Auth::id());
    }

    /**
     * Create a new Google service instance (e.g. Calendar, Drive, Gmail).
     *
     * Example: $google->service('Calendar');
     */
    public function service(string $service): mixed
    {
        if (! $this->client) {
            throw new RuntimeException('Google client has not been booted. Call forUser() first.');
        }

        $className = "Google_Service_{$service}";

        if (! class_exists($className)) {
            throw new InvalidArgumentException("Google service [{$service}] does not exist.");
        }

        return new $className($this->client);
    }

    /**
     * Connect to Google using the given token.
     */
    public function connectUsing(string|array $token): self
    {
        if (! $this->client) {
            throw new RuntimeException('Google client has not been booted. Call forUser() first.');
        }

        $this->client->setAccessToken($token);

        // 🔄 Auto-refresh if expired and refresh token available
        if ($this->client->isAccessTokenExpired() && $this->client->getRefreshToken()) {
            $newToken = $this->client->fetchAccessTokenWithRefreshToken(
                $this->client->getRefreshToken()
            );
            $this->client->setAccessToken($newToken);
        }

        return $this;
    }

    /**
     * Revoke an access token.
     */
    public function revokeToken(string|array|null $token = null): bool
    {
        if (! $this->client) {
            throw new RuntimeException('Google client has not been booted. Call forUser() first.');
        }

        $token = $token ?? $this->client->getAccessToken();

        return $this->client->revokeToken($token);
    }

    /**
     * Connect to Google using a synchronizable model (Account or Calendar).
     */
    public function connectWithSynchronizable(mixed $synchronizable): self
    {
        $token = $this->getTokenFromSynchronizable($synchronizable);

        return $this->connectUsing($token);
    }

    /**
     * Resolve an access token from a synchronizable model.
     */
    protected function getTokenFromSynchronizable(mixed $synchronizable): mixed
    {
        return match (true) {
            $synchronizable instanceof Account  => $synchronizable->token,
            $synchronizable instanceof Calendar => $synchronizable->account->token,
            default => throw new InvalidArgumentException('Invalid synchronizable model.'),
        };
    }

    /**
     * Boot the Google client using credentials for a specific user.
     */
    protected function bootClientForUser(int $userId): void
    {
        $googleApp = $this->googleAppRepository->findByUserId($userId);

        if (! $googleApp) {
            throw new RuntimeException("Google App credentials not configured for user ID {$userId}.");
        }

        $client = new \Google_Client();

        $client->setClientId($googleApp->client_id);
        $client->setClientSecret($googleApp->client_secret);
        $client->setRedirectUri($googleApp->redirect_uri);
        $client->setScopes($googleApp->scopes ?? ['calendar']);
        $client->setApprovalPrompt('force');
        $client->setAccessType('offline');
        $client->setIncludeGrantedScopes(true);

        $this->client = $client;
    }
}
