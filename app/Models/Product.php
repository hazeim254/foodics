<?php

namespace App\Models;

use App\Enums\ProductSyncStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Product extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'foodics_id',
        'daftra_id',
        'status',
        'foodics_name',
        'foodics_sku',
        'foodics_metadata',
        'daftra_metadata',
    ];

    protected function casts(): array
    {
        return [
            'daftra_id' => 'integer',
            'status' => ProductSyncStatus::class,
            'foodics_metadata' => 'array',
            'daftra_metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
