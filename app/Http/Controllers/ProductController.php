<?php

namespace App\Http\Controllers;

use App\Enums\ProductSyncStatus;
use App\Jobs\RetryProductSyncJob;
use App\Jobs\SyncProductsJob;
use App\Models\Product;
use Illuminate\Support\Facades\Cache;

class ProductController extends Controller
{
    public function index()
    {
        $products = auth()->user()->products()
            ->orderByDesc('created_at')
            ->paginate(50);

        $syncing = Cache::has('sync_products_in_progress:'.auth()->id());

        return view('products', compact('products', 'syncing'));
    }

    public function sync()
    {
        $cacheKey = 'sync_products_in_progress:'.auth()->id();

        if (Cache::has($cacheKey)) {
            return redirect()->route('products')
                ->with('status', __('Product sync is already in progress.'));
        }

        Cache::put($cacheKey, true, now()->addMinutes(5));

        SyncProductsJob::dispatch(auth()->user());

        return redirect()->route('products')
            ->with('status', __('Product sync started.'));
    }

    public function syncStatus()
    {
        return response()->json([
            'syncing' => Cache::has('sync_products_in_progress:'.auth()->id()),
        ]);
    }

    public function resync(Product $product)
    {
        if ($product->user_id !== auth()->id()) {
            abort(403);
        }

        if ($product->status === ProductSyncStatus::Synced) {
            return redirect()->route('products')
                ->with('status', __('This product is already synced.'));
        }

        $product->update(['status' => ProductSyncStatus::Failed]);

        RetryProductSyncJob::dispatch($product);

        return redirect()->route('products')
            ->with('status', __('Resyncing product').' '.$product->foodics_name.'…');
    }
}
