<?php

namespace App\Http;

use App\Models\User;
use Illuminate\Contracts\View\View;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

class AuthController
{
    public function daftraRedirect(Request $request): RedirectResponse
    {
        $url = config('services.daftra.oauth_url').'?app_id='.config('services.daftra.client_id')
            .'&redirect_url='.urldecode(config('services.daftra.redirect_uri'));

        return redirect()->away($url);
    }

    public function foodicsRedirect(Request $request): RedirectResponse
    {
        $state = Str::uuid()->toString();
        $request->session()->put('foodics_state', $state);

        $url = config('services.foodics.base_url').'/authorize?'.http_build_query([
            'client_id' => config('services.foodics.client_id'),
            'redirect_uri' => config('services.foodics.redirect_uri'),
            'state' => $state,
        ]);

        return redirect()->away($url);
    }

    public function loginForm(): RedirectResponse|View
    {
        if (auth()->check()) {
            return redirect()->route('home');
        }

        if (session()->has('daftra_account') && session()->has('foodics_account')) {
            return redirect()->route('home');
        }

        return view('login');
    }

    public function daftraCallback(Request $request): RedirectResponse
    {
        $response = Http::asForm()
            ->acceptJson()
            ->post(config('services.daftra.base_url').'/oauth/token', [
                'grant_type' => 'authorization_code',
                'client_id' => config('services.daftra.client_id'),
                'client_secret' => config('services.daftra.client_secret'),
                'code' => $request->input('code'),
                'redirect_uri' => config('services.daftra.redirect_uri'),
            ]);

        if ($response->failed()) {
            throw new \Exception('Failed to exchange Daftra authorization code for tokens.');
        }

        $result = $response->json();

        $request->session()->put('daftra_account', [
            'site_id' => $result['site_id'],
            'subdomain' => $result['subdomain'],
            'access_token' => $result['access_token'],
            'refresh_token' => $result['refresh_token'],
            'expires_in' => $result['expires_in'],
        ]);

        if (! $request->session()->has('foodics_account')) {
            return redirect()->route('login');
        }

        $this->loginOrCreateUser($request);

        return redirect()->route('home');
    }

    public function foodicsCallback(Request $request): RedirectResponse
    {
        if ($request->session()->has('foodics_state')) {
            if ($request->session()->get('foodics_state') !== $request->input('state')) {
                throw new BadRequestHttpException('Invalid state parameter');
            }
        }

        $response = Http::asJson()
            ->acceptJson()
            ->post(config('services.foodics.oauth_url').'/oauth/token', [
                'grant_type' => 'authorization_code',
                'client_id' => config('services.foodics.client_id'),
                'client_secret' => config('services.foodics.client_secret'),
                'code' => $request->input('code'),
                'redirect_uri' => config('services.foodics.redirect_uri'),
            ]);

        if ($response->failed()) {
            \Log::warning('Failed to exchange Foodics authorization code for tokens.', $response->json());
            throw new \Exception('Failed to exchange Foodics authorization code for tokens.');
        }

        $result = $response->json();

        $whoamiResponse = Http::asJson()
            ->acceptJson()
            ->withToken($result['access_token'])
            ->get(config('services.foodics.oauth_url').'/v5/whoami');

        if ($whoamiResponse->failed()) {
            \Log::warning('Failed to fetch Foodics account info.', $whoamiResponse->json());
            throw new \Exception('Failed to fetch Foodics account info.');
        }

        $whoami = $whoamiResponse->json('data');

        $request->session()->put('foodics_account', [
            'business_id' => $whoami['business']['id'],
            'business_ref' => $whoami['business']['reference'],
            'business_name' => $whoami['business']['name'],
            'user_name' => $whoami['user']['name'],
            'user_email' => $whoami['user']['email'],
            'access_token' => $result['access_token'],
            'refresh_token' => $result['refresh_token'],
            'expires_in' => $result['expires_in'],
        ]);

        if (! $request->session()->has('daftra_account')) {
            return redirect()->route('login');
        }

        $this->loginOrCreateUser($request);

        return redirect()->route('home');
    }

    private function loginOrCreateUser(Request $request): void
    {
        $daftra = $request->session()->get('daftra_account');
        $foodics = $request->session()->get('foodics_account');

        $user = User::where('daftra_id', $daftra['site_id'])
            ->where('foodics_ref', $foodics['business_ref'])
            ->first();

        if (! $user) {
            $user = User::create([
                'name' => $foodics['business_name'],
                'email' => $foodics['user_email'],
                'password' => Str::password(40),
                'daftra_id' => $daftra['site_id'],
                'daftra_meta' => ['subdomain' => $daftra['subdomain']],
                'foodics_ref' => $foodics['business_ref'],
                'foodics_id' => $foodics['business_id'],
                'foodics_meta' => [
                    'business_name' => $foodics['business_name'],
                    'business_id' => $foodics['business_id'],
                ],
            ]);
        }

        $user->providerTokens()->updateOrCreate(
            ['provider' => 'daftra'],
            [
                'token' => $daftra['access_token'],
                'refresh_token' => $daftra['refresh_token'],
                'expires_at' => now()->addSeconds($daftra['expires_in']),
            ]
        );

        $user->providerTokens()->updateOrCreate(
            ['provider' => 'foodics'],
            [
                'token' => $foodics['access_token'],
                'refresh_token' => $foodics['refresh_token'],
                'expires_at' => now()->addSeconds($foodics['expires_in']),
            ]
        );

        Auth::login($user);

        $request->session()->forget(['daftra_account', 'foodics_account', 'foodics_state']);
    }
}
