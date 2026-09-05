<?php

namespace Database\Factories;

use App\Models\Vendor;
use App\Models\VendorPayment;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VendorPayment>
 */
class VendorPaymentFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'vendor_id' => Vendor::factory(),
            'amount' => fake()->randomFloat(2, 1000, 200000),
            'payment_date' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'method' => fake()->randomElement(['Cash', 'Bank Transfer', 'Cheque', 'JazzCash', null]),
            'reference' => fake()->optional()->numerify('TXN-#######'),
            'notes' => fake()->optional()->sentence(),
            'currency' => 'PKR',
        ];
    }
}
