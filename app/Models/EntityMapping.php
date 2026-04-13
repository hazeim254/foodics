<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class EntityMapping extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'type',
        'foodics_id',
        'daftra_id',
        'metadata',
        'status',
    ];

    protected function casts(): array
    {
        return ['metadata' => 'array'];
    }

    public function scopeOfType($query, string $type): void
    {
        $query->where('type', $type);
    }
}
