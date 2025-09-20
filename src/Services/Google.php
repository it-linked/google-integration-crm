<?php

namespace Webkul\Google\Services;

use Webkul\Google\Models\Account;
use Webkul\Google\Models\Calendar;
use Webkul\Google\Repositories\GoogleAppRepository;
use Illuminate\Support\Facades\Auth;

class Google
{
    /**
     * The underlying Google Client.
     *
     * @var \Google_Client
     */
    protected $client;

    /**
     * GoogleApp repository instance.
     *
     * @var \Webkul\Google\Repositories\GoogleAppRepository
     */
    protected $googleAppRepository;

    /**
     * Create a new Google service instance.
     *
     * @param  \Webkul\Google\Repositories\GoogleAppRepository  $googleAppRepository
     */
    public function __construct(GoogleAppRepository $googleAppRepository)
    {
        $this->googleAppRepository = $googleAppRepository;

        // By default, bootstrap client for the currently authenticated user.
        if (Auth::check()) {
            $this->bootClientForUser(Auth::id());
        }
    }

    /**
     * Dynamically call methods on the Google client.
     */
    public function __call($method, $args): mixed
    {
        if (! method_exists($this->client, $method)) {
            throw new \Exception("Call to undefined method '{$method}'");
        }

        return call_user_func_array([$this->client, $method], $args);
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
     * Create a new Google service instance.
     *
     * Example: $google->service('Calendar')
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
     * Revoke an access token.
     */
    public function revokeToken(string|array|null $token = null): bool
    {
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
        switch (true) {
            case $synchronizable instanceof Account:
                return $synchronizable->token;

            case $synchronizable instanceof Calendar:
                return $synchronizable->account->token;

            default:
                throw new \Exception('Invalid Synchronizable');
        }
    }

    /**
     * Boot the Google client using credentials for a specific user.
     */
    protected function bootClientForUser(int $userId): void
    {
        $googleApp = $this->googleAppRepository->findByUserId($userId);

        if (! $googleApp) {
            throw new \Exception("Google App credentials not configured for user ID {$userId}.");
        }

        $client = new \Google_Client;

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
