<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Client extends Model
{
    protected $fillable = [
        'user_id',
        'foodics_id',
        'daftra_id',
        'status',
    ];
}
