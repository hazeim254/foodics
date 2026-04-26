<?php

namespace Database\Factories;

use App\Enums\ContactMessageType;
use App\Models\ContactMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<ContactMessage>
 */
class ContactMessageFactory extends Factory
{
    protected $model = ContactMessage::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'name' => fake()->name(),
            'email' => fake()->safeEmail(),
            'phone' => fake()->optional()->phoneNumber(),
            'type' => fake()->randomElement(ContactMessageType::cases()),
            'subject' => fake()->sentence(),
            'message' => fake()->paragraph(),
        ];
    }
}
