<?php

namespace Database\Factories;

use App\Enums\SettingKey;
use App\Models\User;
use App\Models\UserSetting;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<UserSetting>
 */
class UserSettingFactory extends Factory
{
    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'key' => fake()->randomElement(SettingKey::cases()),
            'value' => fake()->word(),
        ];
    }
}
