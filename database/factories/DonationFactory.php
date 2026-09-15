<?php

namespace Database\Factories;

use App\Models\Donation;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Donation>
 */
class DonationFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'internal_reference' => fake()->unique()->regexify('[A-Z]{3}-[0-9]{10}'),
            'amount' => fake()->randomFloat(2, 5, 1000),
            'currency' => 'cad',
            'donor_name' => fake()->name(),
            'donor_email' => fake()->unique()->safeEmail(),
            'status' => 'pending',
        ];
    }

    /**
     * Mark the donation as completed.
     */
    public function completed(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'completed',
            'completed_at' => now(),
        ]);
    }

    /**
     * Provide the Stripe session for the donation.
     */
    public function withStripeSession(?string $sessionId = null): static
    {
        return $this->state(fn (array $attributes) => [
            'stripe_session_id' => $sessionId ?? fake()->regexify('cs_test_[a-zA-Z0-9]{20}'),
        ]);
    }
}
