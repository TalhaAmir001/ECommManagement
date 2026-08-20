<?php

namespace App\Console\Commands;

use App\Models\Customer;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Services\Shopify\ShopifySync as ShopifySyncService;
use Illuminate\Console\Command;
use Throwable;

class ShopifySync extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:sync
        {--type=all : The data to sync: products, customers, orders or all}
        {--reset : Delete existing local data for the selected types before syncing}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Fetch products, customers and orders from Shopify into the local database';

    /**
     * Execute the console command.
     */
    public function handle(ShopifySyncService $sync): int
    {
        $type = strtolower((string) $this->option('type'));

        $types = match ($type) {
            'all' => ['products', 'customers', 'orders'],
            'products', 'customers', 'orders' => [$type],
            default => null,
        };

        if ($types === null) {
            $this->error("Invalid type [{$type}]. Expected products, customers, orders or all.");

            return self::FAILURE;
        }

        if ($this->option('reset')) {
            $this->resetTables($types);
            $this->info('Removed existing local data for: '.implode(', ', $types).'.');
        }

        try {
            foreach ($types as $typeName) {
                $this->info("Syncing {$typeName}...");

                $count = match ($typeName) {
                    'products' => $sync->syncProducts(),
                    'customers' => $sync->syncCustomers(),
                    'orders' => $sync->syncOrders(),
                };

                $this->info("Synced {$count} {$typeName}.");
            }

            $this->info('Shopify sync complete.');

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Sync failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * Remove locally synced data for the given types.
     *
     * Note: order items and orders are always cleared when resetting, because
     * order items depend on products and orders. Re-sync them afterwards.
     *
     * @param  list<string>  $types
     */
    private function resetTables(array $types): void
    {
        OrderItem::query()->delete();
        Order::query()->delete();

        if (in_array('customers', $types, true)) {
            Customer::query()->delete();
        }

        if (in_array('products', $types, true)) {
            Product::query()->delete();
        }
    }
}
