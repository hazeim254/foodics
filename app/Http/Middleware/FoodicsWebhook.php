<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Symfony\Component\HttpFoundation\Response;

class FoodicsWebhook
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->verifySignature($request)) {
            return response('Bad Request', 400);
        }

        $payload = $request->all();
        $businessReference = data_get($payload, 'business.reference');

        if (! $businessReference) {
            Log::warning('Webhook rejected: Missing business reference', [
                'event' => $payload['event'] ?? null,
                'reference' => $payload['business']['reference'] ?? null,
            ]);

            return response()->json(['ok' => true], 200);
        }

        if (! User::query()->where('foodics_ref', $businessReference)->exists()) {
            Log::warning('Webhook rejected: Business reference not found in users table', [
                'business_reference' => $businessReference,
                'event' => $payload['event'] ?? null,
                'reference' => $payload,
            ]);

            return response()->json(['ok' => true], 200);
        }

        return $next($request);
    }

    private function verifySignature(Request $request): bool
    {
        // TODO: find out how to verify signature
        //        $foodicsSignature = $request->header('X-Signature');
        //        $payload = $request->getContent();
        //        $calculatedSignature = hash_hmac('sha256', $payload, config('services.foodics.webhook_secret'));
        //        $calculatedSignature512 = hash_hmac('sha512', $payload, config('services.foodics.webhook_secret'));

        return true;
        //        $foodicsSignature = $request->header('X-Signature');
    }
}
