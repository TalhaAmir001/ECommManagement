<?php

namespace Database\Factories;

use App\Models\Order;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Order>
 */
class OrderFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'customer_id' => null,
            'number' => strtoupper(fake()->unique()->bothify('ORD-#######')),
            'status' => fake()->randomElement([
                'pending', 'processing', 'shipped', 'delivered', 'delivered', 'delivered', 'cancelled',
            ]),
            'total' => 0,
            'created_at' => fake()->dateTimeBetween('-90 days', 'now'),
        ];
    }
}
