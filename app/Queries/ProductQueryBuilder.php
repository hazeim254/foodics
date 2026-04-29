<?php

namespace App\Queries;

use Illuminate\Database\Eloquent\Builder;

class ProductQueryBuilder
{
    public function apply(Builder $query, array $filters): Builder
    {
        if (! empty($filters['search'])) {
            $search = $filters['search'];
            $query->where(function (Builder $q) use ($search) {
                $q->where('foodics_name', 'like', "%{$search}%")
                    ->orWhere('foodics_sku', 'like', "%{$search}%");
            });
        }

        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['price_from'])) {
            $query->where('price', '>=', (float) $filters['price_from']);
        }

        if (! empty($filters['price_to'])) {
            $query->where('price', '<=', (float) $filters['price_to']);
        }

        if (! empty($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (! empty($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        $sortBy = $filters['sort_by'] ?? 'created_at';
        $sortDir = $filters['sort_dir'] ?? 'desc';

        if ($sortBy === 'status') {
            $query->orderByRaw('CAST(status AS TEXT) '.strtoupper($sortDir));
        } else {
            $query->orderBy($sortBy, $sortDir);
        }

        return $query;
    }
}
