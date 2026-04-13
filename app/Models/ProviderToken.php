<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProviderToken extends Model
{
    protected $fillable = [
        'user_id', 'provider', 'token', 'refresh_token', 'expires_at',
    ];

    protected $casts = [
        'token' => 'encrypted',
        'refresh_token' => 'encrypted',
        'expires_at' => 'datetime',
    ];
}
