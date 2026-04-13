<?php

namespace App\Http\Controllers;

use App\Services\WebhookLogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class WebhooksController extends Controller
{
    public function __invoke(Request $request, WebhookLogService $webhookLogService)
    {
        try {
            $webhookLogService->log($request);
        } catch (\Exception $e) {
            Log::error('Failed to log webhook', [
                'error' => $e->getMessage(),
                'payload' => $request->all(),
            ]);
        }

        return response()->json(['ok' => true], 200);
    }
}
