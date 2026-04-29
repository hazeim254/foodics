<?php

namespace Database\Factories;

use App\Enums\InvoiceType;
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
            'total_price' => fake()->randomFloat(2, 10, 1000),
            'daftra_no' => fake()->optional()->numerify('INV-#####'),
        ];
    }

    public function creditNote(Invoice $original): static
    {
        return $this->state(fn (array $attributes) => [
            'type' => InvoiceType::CreditNote,
            'original_invoice_id' => $original->id,
            'foodics_id' => fake()->uuid(),
            'foodics_reference' => (string) ((int) ($attributes['foodics_reference'] ?? fake()->randomNumber(5)) + 1),
        ]);
    }
}
