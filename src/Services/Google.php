<?php

namespace Webkul\Google\Services;

use Google_Client;
use Webkul\Google\Models\Account;
use Webkul\Google\Models\Calendar;
use Webkul\Google\Repositories\GoogleAppRepository;
use Webkul\Google\Repositories\AccountRepository;
use RuntimeException;
use BadMethodCallException;
use Illuminate\Support\Facades\Log;

class Google
{
    protected ?\Webkul\Google\Contracts\GoogleApp $googleApp = null;
    protected ?Google_Client $client = null;

    public function __construct(
        protected GoogleAppRepository $googleAppRepository,
        protected AccountRepository $accountRepository
    ) {}

    protected function initGoogleApp(): void
    {
        if ($this->googleApp) return;

        $this->googleApp = $this->googleAppRepository->first();

        if (! $this->googleApp) {
            Log::error('Google App configuration not found.');
            throw new RuntimeException('Google App configuration not found.');
        }

        Log::info('Google App initialized', ['client_id' => $this->googleApp->client_id]);
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
        $client->setAccessType('offline');
        $client->setPrompt('consent');
        $client->setIncludeGrantedScopes(true);

        $this->client = $client;

        Log::info('Google Client initialized');
    }

    public function __call($method, $args): mixed
    {
        $this->initClient();

        if (! method_exists($this->client, $method)) {
            Log::error("Attempted to call undefined method {$method}");
            throw new BadMethodCallException("Call to undefined method '{$method}'");
        }

        Log::info("Calling Google_Client method: {$method}");
        return $this->client->{$method}(...$args);
    }

    public function service(string $service): mixed
    {
        $this->initClient();
        $this->refreshIfExpired();
        $className = "Google_Service_{$service}";

        Log::info("Creating Google service instance: {$className}");
        return new $className($this->client);
    }

    public function authenticate(string $code): array
    {
        $this->initClient();

        $token = $this->client->fetchAccessTokenWithAuthCode($code);

        if (isset($token['error'])) {
            Log::error('Google token exchange failed', ['error' => $token['error']]);
            throw new RuntimeException('Google token exchange failed: ' . $token['error']);
        }

        $this->client->setAccessToken($token);
        Log::info('Google token successfully retrieved', ['token' => $token]);
        return $token;
    }

    public function connectUsing(array $token): self
    {
        $this->initClient();
        $this->client->setAccessToken($token);
        Log::info('Connected using token', ['token' => $token]);
        return $this;
    }

    public function revokeToken(array|string|null $token = null): bool
    {
        $this->initClient();
        $token = $token ?? $this->client->getAccessToken();
        Log::info('Revoking token', ['token' => $token]);
        return $this->client->revokeToken($token);
    }

    public function refreshIfExpired(Account $account = null): void
    {
        if (! $this->client) return;

        if ($this->client->isAccessTokenExpired()) {
            $refreshToken = $this->client->getRefreshToken();

            if ($refreshToken) {
                $newToken = $this->client->fetchAccessTokenWithRefreshToken($refreshToken);
                $this->client->setAccessToken(array_merge($this->client->getAccessToken(), $newToken));

                Log::info('Token refreshed successfully', ['newToken' => $newToken]);

                if ($account) {
                    $this->accountRepository->update([
                        'token' => $this->client->getAccessToken(),
                    ], $account->id);

                    Log::info('Refreshed token saved to database', ['account_id' => $account->id]);
                }
            } else {
                Log::warning('Access token expired and no refresh token available.');
                throw new RuntimeException('Access token expired and no refresh token available.');
            }
        }
    }

    public function connectWithSynchronizable(mixed $synchronizable): self
    {
        $token = $this->getTokenFromSynchronizable($synchronizable);
        $this->connectUsing($token);
        $this->refreshIfExpired($synchronizable instanceof Account ? $synchronizable : $synchronizable->account);
        return $this;
    }

    protected function getTokenFromSynchronizable(mixed $synchronizable): array
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
