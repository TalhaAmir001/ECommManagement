<?php

namespace Database\Factories;

use App\Models\Vendor;
use App\Models\VendorPurchase;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<VendorPurchase>
 */
class VendorPurchaseFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $quantity = fake()->randomFloat(3, 1, 500);
        $unitCost = fake()->randomFloat(2, 50, 5000);

        return [
            'vendor_id' => Vendor::factory(),
            'reference' => 'INV-'.fake()->numerify('####'),
            'item_description' => fake()->randomElement([
                'Cotton fabric', 'Raw polyester', 'Cardboard boxes',
                'Poly bags', 'Thread spools', 'Printed labels',
            ]),
            'quantity' => $quantity,
            'unit' => fake()->randomElement(['kg', 'pcs', 'roll', 'meter', null]),
            'unit_cost' => $unitCost,
            'total_cost' => round($quantity * $unitCost, 2),
            'purchase_date' => fake()->dateTimeBetween('-6 months', 'now')->format('Y-m-d'),
            'currency' => 'PKR',
            'notes' => fake()->optional()->sentence(),
        ];
    }
}
