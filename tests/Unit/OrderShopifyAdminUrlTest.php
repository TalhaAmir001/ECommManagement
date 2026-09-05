<?php

namespace Tests\Unit;

use App\Models\Order;
use Tests\TestCase;

class OrderShopifyAdminUrlTest extends TestCase
{
    private function makeOrder(?string $shopifyId): Order
    {
        return new Order(['shopify_id' => $shopifyId]);
    }

    public function test_builds_admin_url_from_graphql_gid(): void
    {
        config()->set('shopify.shop', 'my-store');

        $order = $this->makeOrder('gid://shopify/Order/123456789');

        $this->assertSame(
            'https://admin.shopify.com/store/my-store/orders/123456789',
            $order->shopifyAdminUrl()
        );
    }

    public function test_accepts_a_bare_numeric_shopify_id(): void
    {
        config()->set('shopify.shop', 'my-store');

        $order = $this->makeOrder('987654');

        $this->assertSame(
            'https://admin.shopify.com/store/my-store/orders/987654',
            $order->shopifyAdminUrl()
        );
    }

    public function test_returns_null_when_order_has_no_shopify_id(): void
    {
        config()->set('shopify.shop', 'my-store');

        $this->assertNull($this->makeOrder(null)->shopifyAdminUrl());
        $this->assertNull($this->makeOrder('')->shopifyAdminUrl());
    }

    public function test_returns_null_when_shopify_id_is_not_a_numeric_gid(): void
    {
        config()->set('shopify.shop', 'my-store');

        $this->assertNull($this->makeOrder('gid://shopify/Order/not-a-number')->shopifyAdminUrl());
        $this->assertNull($this->makeOrder('gid://shopify/Order/')->shopifyAdminUrl());
    }

    public function test_returns_null_when_no_shop_handle_is_configured(): void
    {
        config()->set('shopify.shop', null);

        $order = $this->makeOrder('gid://shopify/Order/123456789');

        $this->assertNull($order->shopifyAdminUrl());
    }
}