<?php

namespace Database\Seeders;

use App\Models\Customer;
use App\Models\Order;
use App\Models\Product;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call([
            CourierProvidersSeeder::class,
            JournalAccountsSeeder::class,
        ]);

        $customers = Customer::factory(24)->create();
        $products = Product::factory(40)->create();

        for ($i = 0; $i < 300; $i++) {
            $order = Order::factory()->create([
                'customer_id' => $customers->random()->id,
            ]);

            $itemCount = rand(1, 4);
            $total = 0.0;

            foreach ($products->random($itemCount) as $product) {
                $quantity = rand(1, 3);
                $order->items()->create([
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $product->price,
                ]);
                $total += $quantity * $product->price;
            }

            $order->update(['total' => round($total, 2)]);
        }
    }
}
