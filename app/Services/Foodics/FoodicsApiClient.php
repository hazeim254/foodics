<?php

namespace App\Services\Foodics;

use App\Services\UserContext;
use Illuminate\Http\Client\PendingRequest;

/**
 * @mixin PendingRequest
 */
class FoodicsApiClient
{
    private ?PendingRequest $client = null;

    public function __construct(protected UserContext $userContext) {}

    public function __call($name, $arguments)
    {
        if (! in_array($name, ['get', 'post', 'put', 'patch', 'delete'])) {
            return $this->client()->$name(...$arguments);
        }

        $response = $this->client()->$name(...$arguments);

        if ($response->status() === 401) {
            $this->refreshToken();
            $response = $this->client()->$name(...$arguments);
        }

        return $response;
    }

    private function client(): PendingRequest
    {
        if ($this->client === null) {
            $user = $this->userContext->get();
            $this->client = \Http::asJson()
                ->acceptJson()
                ->baseUrl(config('services.foodics.oauth_url'))
                ->withToken($user->getFoodicsToken()->token);
        }

        return $this->client;
    }

    private function refreshToken(): void
    {
        $user = $this->userContext->get();

        $response = $this->client()->post('/oauth/token', [
            'refresh_token' => $user->getFoodicsToken()->refresh_token,
            'grant_type' => 'refresh_token',
            'client_id' => config('services.foodics.client_id'),
            'client_secret' => config('services.foodics.client_secret'),
        ]);

        if ($response->failed()) {
            throw new \Exception('Failed to refresh Foodics token for user: '.$user->id);
        }

        $result = $response->json();
        $user->getFoodicsToken()->update([
            'token' => $result['access_token'],
            'expires_at' => now()->addSeconds($result['expires_in']),
        ]);

        $this->client = null;
        $this->client()->withToken($result['access_token']);
    }
}
