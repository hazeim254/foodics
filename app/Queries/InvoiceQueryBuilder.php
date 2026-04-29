<?php

namespace App\Queries;

use Illuminate\Database\Eloquent\Builder;

class InvoiceQueryBuilder
{
    public function apply(Builder $query, array $filters): Builder
    {
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function (Builder $q) use ($search) {
                $q->where('foodics_reference', 'like', "%{$search}%")
                    ->orWhere('daftra_no', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['foodics_ref'])) {
            $query->where('foodics_reference', 'like', "%{$filters['foodics_ref']}%");
        }

        if (! empty($filters['daftra_no'])) {
            $query->where('daftra_no', 'like', "%{$filters['daftra_no']}%");
        }

        if (! empty($filters['amount_from'])) {
            $query->where('total_price', '>=', (float) $filters['amount_from']);
        }

        if (! empty($filters['amount_to'])) {
            $query->where('total_price', '<=', (float) $filters['amount_to']);
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['type'])) {
            $query->where('type', $filters['type']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDir = $filters['sort_dir'] ?? 'desc';

        if (in_array($sortBy, ['status', 'type'])) {
            $query->orderByRaw("CAST({$sortBy} AS TEXT) ".strtoupper($sortDir));
        } else {
            $query->orderBy($sortBy, $sortDir);
        }

        return $query;
    }
}
