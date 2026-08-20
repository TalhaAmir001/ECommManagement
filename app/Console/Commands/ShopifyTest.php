<?php

namespace App\Console\Commands;

use App\Services\Shopify\ShopifyClient;
use Illuminate\Console\Command;
use Throwable;

class ShopifyTest extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'shopify:test';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Verify the connection to the configured Shopify store';

    /**
     * Execute the console command.
     */
    public function handle(ShopifyClient $shopify): int
    {
        $this->info('Shopify store: '.$shopify->shop());
        $this->info('Admin API version: '.$shopify->apiVersion());

        try {
            $data = $shopify->graphql('{ shop { name myshopifyDomain } }');

            $shop = $data['shop'] ?? null;

            if (! is_array($shop)) {
                $this->error('Connected, but the response did not include shop information.');

                return self::FAILURE;
            }

            $this->info('Connection successful.');
            $this->table(
                ['Field', 'Value'],
                [
                    ['Name', $shop['name'] ?? ''],
                    ['Domain', $shop['myshopifyDomain'] ?? ''],
                ],
            );

            return self::SUCCESS;
        } catch (Throwable $e) {
            $this->error('Connection failed: '.$e->getMessage());

            return self::FAILURE;
        }
    }
}
