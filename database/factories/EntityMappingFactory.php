<?php

namespace Database\Factories;

use App\Models\EntityMapping;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<EntityMapping>
 */
class EntityMappingFactory extends Factory
{
    protected $model = EntityMapping::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'type' => 'tax',
            'foodics_id' => fake()->uuid(),
            'daftra_id' => fake()->randomNumber(5),
            'metadata' => ['name' => 'VAT', 'rate' => 5],
            'status' => 'synced',
        ];
    }
}
