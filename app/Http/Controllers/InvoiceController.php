<?php

namespace App\Http\Controllers;

use App\Jobs\SyncInvoicesJob;
use Illuminate\Support\Facades\Cache;

class InvoiceController extends Controller
{
    public function index()
    {
        $invoices = auth()->user()->invoices()
            ->orderByDesc('created_at')
            ->paginate(50);

        $syncing = Cache::has('sync_in_progress:'.auth()->id());

        return view('invoices', compact('invoices', 'syncing'));
    }

    public function sync()
    {
        $cacheKey = 'sync_in_progress:'.auth()->id();

        if (Cache::has($cacheKey)) {
            return redirect()->route('invoices')
                ->with('status', 'Sync is already in progress.');
        }

        Cache::put($cacheKey, true, now()->addMinutes(5));

        SyncInvoicesJob::dispatch(auth()->user());

        return redirect()->route('invoices')
            ->with('status', 'Sync started.');
    }

    public function syncStatus()
    {
        return response()->json([
            'syncing' => Cache::has('sync_in_progress:'.auth()->id()),
        ]);
    }
}
