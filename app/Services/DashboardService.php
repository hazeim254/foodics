<?php

namespace App\Services;

use App\Enums\InvoiceSyncStatus;
use App\Enums\ProductSyncStatus;
use App\Enums\SettingKey;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class DashboardService
{
    public function getInvoiceStats(User $user): array
    {
        $total = $user->invoices()->count();
        $synced = $user->invoices()->where('status', InvoiceSyncStatus::Synced)->count();
        $failed = $user->invoices()->where('status', InvoiceSyncStatus::Failed)->count();
        $pending = $user->invoices()->where('status', InvoiceSyncStatus::Pending)->count();
        $successRate = $total > 0 ? round(($synced / $total) * 100, 1) : 0;

        return [
            'total' => $total,
            'synced' => $synced,
            'failed' => $failed,
            'pending' => $pending,
            'success_rate' => $successRate,
        ];
    }

    public function getProductStats(User $user): array
    {
        $total = $user->products()->count();
        $synced = $user->products()->where('status', ProductSyncStatus::Synced)->count();
        $failed = $user->products()->where('status', ProductSyncStatus::Failed)->count();
        $pending = $user->products()->where('status', ProductSyncStatus::Pending)->count();
        $successRate = $total > 0 ? round(($synced / $total) * 100, 1) : 0;

        return [
            'total' => $total,
            'synced' => $synced,
            'failed' => $failed,
            'pending' => $pending,
            'success_rate' => $successRate,
        ];
    }

    public function getSyncOverTime(User $user): array
    {
        $days = collect(range(0, 6))->reverse()->map(fn (int $offset) => now()->subDays($offset)->format('Y-m-d'));

        $labels = $days->map(fn (string $date) => Carbon::parse($date)->format('M j'))->values()->all();

        $invoiceSynced = $this->getDailyCounts($user->invoices(), InvoiceSyncStatus::Synced, $days);
        $invoiceFailed = $this->getDailyCounts($user->invoices(), InvoiceSyncStatus::Failed, $days);
        $productSynced = $this->getDailyCounts($user->products(), ProductSyncStatus::Synced, $days);
        $productFailed = $this->getDailyCounts($user->products(), ProductSyncStatus::Failed, $days);

        return [
            'labels' => $labels,
            'invoices' => [
                'synced' => $invoiceSynced,
                'failed' => $invoiceFailed,
            ],
            'products' => [
                'synced' => $productSynced,
                'failed' => $productFailed,
            ],
        ];
    }

    public function getDefaultSettings(User $user): array
    {
        $clientId = $user->setting(SettingKey::DaftraDefaultClientId);
        $branchId = $user->setting(SettingKey::DaftraDefaultBranchId);

        return [
            'client_id' => $clientId,
            'branch_id' => $branchId,
        ];
    }

    /**
     * @return int[]
     */
    private function getDailyCounts(HasMany $relationship, InvoiceSyncStatus|ProductSyncStatus $status, Collection $days): array
    {
        $counts = $relationship
            ->where('status', $status)
            ->where('created_at', '>=', $days->first().' 00:00:00')
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('COUNT(*) as count'))
            ->groupByRaw('DATE(created_at)')
            ->pluck('count', 'date')
            ->toArray();

        return $days->map(fn (string $date) => (int) ($counts[$date] ?? 0))->values()->all();
    }
}
