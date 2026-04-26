<?php

namespace App\Services\Daftra;

use App\Enums\SettingKey;
use App\Models\User;
use Illuminate\Http\Client\PendingRequest;

/**
 * @mixin PendingRequest
 */
class DaftraApiClient
{
    private PendingRequest $client;

    private ?string $branchId = null;

    public function __construct(protected User $user)
    {
        $this->branchId = $user->setting(SettingKey::DaftraDefaultBranchId);
        $this->client = \Http::asJson()
            ->acceptJson()
            ->baseUrl(config('services.daftra.base_url'))
            ->withToken($user->getDaftraToken()->token)
            ->withHeaders([
                'Site-Id' => $user->daftra_id,
            ]);
    }

    public function __call($name, $arguments)
    {
        if (! in_array($name, ['get', 'post', 'put', 'patch', 'delete'])) {
            return $this->client->$name(...$arguments);
        }

        if (isset($arguments[0])) {
            $arguments[0] = $this->appendBranchIdToUrl($arguments[0]);
        }

        $response = $this->client->$name(...$arguments);

        if ($response->status() === 401) {
            $this->refreshToken();
            $response = $this->client->$name(...$arguments);
        }

        return $response;
    }

    private function appendBranchIdToUrl(string $url): string
    {
        if ((string) $this->branchId === '' || (string) $this->branchId === '1') {
            return $url;
        }

        if (str_contains($url, 'request_branch_id=')) {
            return $url;
        }

        $separator = str_contains($url, '?') ? '&' : '?';

        return $url.$separator.'request_branch_id='.urlencode((string) $this->branchId);
    }

    private function refreshToken(): void
    {
        $response = $this->client->post('/oauth/token', [
            'refresh_token' => $this->user->getDaftraToken()->refresh_token,
            'grant_type' => 'refresh_token',
            'client_id' => config('services.daftra.client_id'),
            'client_secret' => config('services.daftra.client_secret'),
        ]);

        if ($response->failed()) {
            throw new \Exception('Failed to refresh Daftra token for user: '.$this->user->id);
        }

        $result = $response->json();
        $this->user->getDaftraToken()->update([
            'token' => $result['access_token'],
            'expires_at' => now()->addSeconds($result['expires_in']),
        ]);

        $this->client->withToken($response['access_token']);
    }
}
