<?php

namespace App\Models;

use App\Enums\InvoiceSyncStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'foodics_id',
        'daftra_id',
        'foodics_reference',
        'status',
        'foodics_metadata',
        'daftra_metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => InvoiceSyncStatus::class,
            'foodics_metadata' => 'array',
            'daftra_metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
