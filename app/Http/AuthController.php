<?php

namespace App\Http;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Exception\BadRequestException;

class AuthController
{
    public function daftraCallback(Request $request)
    {
        if (! $request->user()->foodics_id) {
            return $this->foodicsRedirect($request);
        }
    }

    public function foodicsCallback(Request $request)
    {
        // TODO: Handle session not found
        if ($request->session()->get('foodics_state') !== $request->input('state')) {
            throw new BadRequestException('Invalid state parameter');
        }

        try {
            $result = \Http::asJson()
                ->acceptJson()
                ->post('https://api-sandbox.foodics.com/oauth/token', [
                    'grant_type' => 'authorization_code',
                    'client_id' => config('services.foodics.client_id'),
                    'client_secret' => config('services.foodics.client_secret'),
                    'code' => $request->input('code'),
                    'redirect_uri' => config('services.foodics.redirect_uri'),
                ])->json();

            $user = $request->user();

            $user->providerTokens()->firstOrCreate([
                'user_id' => $user->id,
                'provider' => 'foodics',
            ], [
                'token' => $result['access_token'],
                'refresh_token' => $result['refresh_token'],
                'expires_at' => now()->addSeconds($result['expires_in']),
            ]);

            $foodicsRef = \Http::asJson()
                ->withToken($result['access_token'])
                ->get('https://api-sandbox.foodics.com/v5/whoami')->json('data.business.reference');
            $user->foodics_ref = $foodicsRef;
            $user->save();

            return redirect()->route('home');
        } catch (\Exception $e) {
            report($e);
        }
    }

    public function foodicsRedirect(Request $request): RedirectResponse
    {
        $foodicsState = \Str::uuid()->toString();
        $request->session()->put('foodics_state', $foodicsState);
        $url = 'https://console-sandbox.foodics.com/authorize?'.http_build_query([
            'client_id' => config('services.foodics.client_id'),
            'state' => $foodicsState,
        ]);

        return redirect()->away($url);
    }
}
