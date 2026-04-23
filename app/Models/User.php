<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\HasSettings;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use HasFactory, HasSettings, Notifiable;

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

    public function hasDaftraConnection(): bool
    {
        return $this->getDaftraToken() !== null;
    }

    public function hasFoodicsConnection(): bool
    {
        return $this->getFoodicsToken() !== null;
    }

    public function daftraSubdomain(): ?string
    {
        return $this->daftra_meta['subdomain'] ?? null;
    }

    public function daftraBusinessName(): ?string
    {
        return $this->daftra_meta['business_name'] ?? null;
    }

    public function foodicsBusinessName(): ?string
    {
        return $this->foodics_meta['business_name'] ?? null;
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

    public function invoices(): HasMany
    {
        return $this->hasMany(Invoice::class);
    }

    public function products(): HasMany
    {
        return $this->hasMany(Product::class);
    }

    public function providerTokens(): HasMany
    {
        return $this->hasMany(ProviderToken::class);
    }
}
