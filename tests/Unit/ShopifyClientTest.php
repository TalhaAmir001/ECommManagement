<?php

namespace Tests\Unit;

use App\Services\Shopify\ShopifyClient;
use PHPUnit\Framework\TestCase;

class ShopifyClientTest extends TestCase
{
    public function test_order_id_to_gid_normalizes_numeric_ids(): void
    {
        $client = new ShopifyClient();

        $this->assertSame(
            'gid://shopify/Order/7761549852917',
            $client->orderIdToGid('7761549852917')
        );
    }

    public function test_order_id_to_gid_leaves_valid_global_ids_untouched(): void
    {
        $client = new ShopifyClient();

        $this->assertSame(
            'gid://shopify/Order/7761549852917',
            $client->orderIdToGid('gid://shopify/Order/7761549852917')
        );
    }
}
