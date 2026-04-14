<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'password',
        'daftra_id',
        'daftra_meta',
        'foodics_ref',
        'foodics_id',
        'foodics_meta',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
    ];

    public function getDaftraToken(): ?ProviderToken
    {
        return $this->providerTokens->firstWhere('provider', 'daftra');
    }

    public function getFoodicsToken(): ?ProviderToken
    {
        return $this->providerTokens->firstWhere('provider', 'foodics');
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'daftra_meta' => 'array',
            'foodics_meta' => 'array',
        ];
    }

    public function providerTokens(): HasMany
    {
        return $this->hasMany(ProviderToken::class);
    }
}
