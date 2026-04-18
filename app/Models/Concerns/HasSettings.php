<?php

namespace App\Models\Concerns;

use App\Enums\SettingKey;
use App\Models\UserSetting;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Adds per-user settings helpers.
 *
 * @property-read Collection<int, UserSetting> $settings
 */
trait HasSettings
{
    public function settings(): HasMany
    {
        return $this->hasMany(UserSetting::class);
    }

    /**
     * Read a setting value for this user. Returns null when no row exists.
     */
    public function setting(SettingKey $key): ?string
    {
        if ($this->relationLoaded('settings')) {
            $row = $this->settings->first(fn (UserSetting $setting) => $setting->key === $key);

            return $row?->value;
        }

        return $this->settings()
            ->where('key', $key->value)
            ->value('value');
    }

    /**
     * Upsert a setting row for this user. Passing null stores null (does not delete the row).
     */
    public function setSetting(SettingKey $key, ?string $value): UserSetting
    {
        return $this->settings()->updateOrCreate(
            ['key' => $key->value],
            ['value' => $value],
        );
    }

    /**
     * Delete the row for this setting, if any.
     */
    public function forgetSetting(SettingKey $key): void
    {
        $this->settings()->where('key', $key->value)->delete();
    }
}
