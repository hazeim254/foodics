<?php

namespace Database\Factories;

use App\Models\Invoice;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Invoice>
 */
class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

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
            'foodics_reference' => fake()->randomNumber(5),
        ];
    }
}
