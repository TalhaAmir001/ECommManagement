<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $categories = ['Electronics', 'Apparel', 'Home & Living', 'Beauty', 'Sports'];

        $price = fake()->randomFloat(2, 15, 600);

        return [
            'name' => fake()->unique()->words(3, true),
            'sku' => strtoupper(fake()->unique()->bothify('SKU-####-??')),
            'category' => fake()->randomElement($categories),
            'price' => $price,
            'cost' => round($price * fake()->randomFloat(2, 0.4, 0.7), 2),
            'stock' => fake()->numberBetween(0, 120),
            'weight_kg' => fake()->randomFloat(3, 0.05, 8),
        ];
    }
}
