<?php

namespace Database\Factories;

use App\Models\Donation;
use App\Models\Receipt;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Receipt>
 */
class ReceiptFactory extends Factory
{
    /**
     * Define the model's default state.
     */
    public function definition(): array
    {
        return [
            'donation_id' => Donation::factory(),
            'number' => fake()->unique()->regexify('SI2-0001317-[0-9]{7}'),
            'issued_at' => now(),
        ];
    }
}
