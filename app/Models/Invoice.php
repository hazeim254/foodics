<?php

namespace App\Models;

use App\Enums\InvoiceSyncStatus;
use App\Enums\InvoiceType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Invoice extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'foodics_id',
        'daftra_id',
        'foodics_reference',
        'type',
        'original_invoice_id',
        'status',
        'total_price',
        'daftra_no',
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
            'type' => InvoiceType::class,
            'total_price' => 'decimal:2',
            'foodics_metadata' => 'array',
            'daftra_metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function originalInvoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class, 'original_invoice_id');
    }

    public function creditNotes(): HasMany
    {
        return $this->hasMany(Invoice::class, 'original_invoice_id');
    }
}
