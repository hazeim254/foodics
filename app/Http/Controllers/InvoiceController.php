<?php

namespace App\Http\Controllers;

use App\Enums\InvoiceSyncStatus;
use App\Http\Requests\InvoiceFiltersRequest;
use App\Jobs\RetryInvoiceSyncJob;
use App\Jobs\SyncInvoicesJob;
use App\Models\Invoice;
use App\Queries\InvoiceQueryBuilder;
use Illuminate\Support\Facades\Cache;

class InvoiceController extends Controller
{
    public function index(InvoiceFiltersRequest $request)
    {
        $filters = $request->validated();

        $query = Invoice::query()->where('user_id', auth()->id());

        $invoices = app(InvoiceQueryBuilder::class)
            ->apply($query, $filters)
            ->paginate(50)
            ->withQueryString();

        $syncing = Cache::has('sync_in_progress:'.auth()->id());

        return view('invoices', compact('invoices', 'syncing', 'filters'));
    }

    public function sync()
    {
        $cacheKey = 'sync_in_progress:'.auth()->id();

        if (Cache::has($cacheKey)) {
            return redirect()->route('invoices')
                ->with('status', __('Sync is already in progress.'));
        }

        Cache::put($cacheKey, true, now()->addMinutes(5));

        SyncInvoicesJob::dispatch(auth()->user());

        return redirect()->route('invoices')
            ->with('status', __('Sync started.'));
    }

    public function syncStatus()
    {
        return response()->json([
            'syncing' => Cache::has('sync_in_progress:'.auth()->id()),
        ]);
    }

    public function retrySync(Invoice $invoice)
    {
        if ($invoice->user_id !== auth()->id()) {
            abort(403);
        }

        if ($invoice->status === InvoiceSyncStatus::Synced) {
            return redirect()->route('invoices')
                ->with('status', __('This invoice is already synced.'));
        }

        $invoice->update(['status' => InvoiceSyncStatus::Failed]);

        RetryInvoiceSyncJob::dispatch($invoice);

        return redirect()->route('invoices')
            ->with('status', __('Retrying sync for').' '.$invoice->foodics_reference.'…');
    }
}
