<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'foodics_id' => fake()->uuid(),
            'daftra_id' => fake()->randomNumber(5),
            'status' => 'synced',
            'foodics_name' => fake()->words(3, true),
        ];
    }
}
