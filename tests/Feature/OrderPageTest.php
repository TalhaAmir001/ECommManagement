<?php

namespace Tests\Feature;

use App\Models\Customer;
use App\Models\Order;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderPageTest extends TestCase
{
    use RefreshDatabase;

    private function makeOrder(array $attributes = []): Order
    {
        return Order::factory()->create(array_merge([
            'status' => 'pending',
            'financial_status' => 'PENDING',
            'fulfillment_status' => 'UNFULFILLED',
            'total' => 100,
            'created_at' => now()->subDays(2),
        ], $attributes));
    }

    public function test_the_orders_page_renders_orders_with_shopify_columns(): void
    {
        $customer = Customer::factory()->create(['name' => 'Jane Doe', 'email' => 'jane@example.com']);

        $this->makeOrder([
            'number' => '#1001',
            'customer_id' => $customer->id,
            'financial_status' => 'PAID',
            'fulfillment_status' => 'FULFILLED',
        ]);
        $this->makeOrder(['number' => '#1002']);

        $this->get('/orders')
            ->assertOk()
            ->assertSee('#1001')
            ->assertSee('#1002')
            ->assertSee('Jane Doe')
            ->assertSee('Paid')
            ->assertSee('Unfulfilled')
            ->assertSee('Payment')
            ->assertSee('Fulfillment')
            ->assertSee('Items');
    }

    public function test_it_filters_orders_by_payment_status(): void
    {
        $this->makeOrder(['number' => '#1001', 'financial_status' => 'PAID']);
        $this->makeOrder(['number' => '#1002', 'financial_status' => 'PENDING']);

        $this->get('/orders?payment=PAID')
            ->assertOk()
            ->assertSee('#1001')
            ->assertDontSee('#1002');
    }

    public function test_it_filters_orders_by_fulfillment_status(): void
    {
        $this->makeOrder(['number' => '#1001', 'fulfillment_status' => 'FULFILLED']);
        $this->makeOrder(['number' => '#1002', 'fulfillment_status' => 'UNFULFILLED']);

        $this->get('/orders?fulfillment=FULFILLED')
            ->assertOk()
            ->assertSee('#1001')
            ->assertDontSee('#1002');
    }

    public function test_it_filters_orders_by_open_and_closed_status(): void
    {
        $this->makeOrder(['number' => '#1001', 'financial_status' => 'PAID', 'fulfillment_status' => 'FULFILLED']);
        $this->makeOrder(['number' => '#1002', 'financial_status' => 'PENDING', 'fulfillment_status' => 'UNFULFILLED']);

        $this->get('/orders?status=open')
            ->assertOk()
            ->assertSee('#1002')
            ->assertDontSee('#1001');

        $this->get('/orders?status=closed')
            ->assertOk()
            ->assertSee('#1001')
            ->assertDontSee('#1002');
    }

    public function test_it_searches_orders_by_customer_and_number(): void
    {
        $customer = Customer::factory()->create(['name' => 'Zara Smith', 'email' => 'zara@example.com']);

        $this->makeOrder(['number' => '#1001', 'customer_id' => $customer->id]);
        $this->makeOrder(['number' => '#1002']);

        $this->get('/orders?q=Zara')
            ->assertOk()
            ->assertSee('#1001')
            ->assertDontSee('#1002');

        $this->get('/orders?q=%231001')
            ->assertOk()
            ->assertSee('#1001')
            ->assertDontSee('#1002');
    }

    public function test_it_filters_orders_by_date_preset(): void
    {
        $this->makeOrder(['number' => '#1001', 'created_at' => now()->subDays(1)]);
        $this->makeOrder(['number' => '#1002', 'created_at' => now()->subDays(40)]);

        $this->get('/orders?date=30d')
            ->assertOk()
            ->assertSee('#1001')
            ->assertDontSee('#1002');
    }

    public function test_it_sorts_orders_by_total(): void
    {
        $this->makeOrder(['number' => '#1001', 'total' => 50]);
        $this->makeOrder(['number' => '#1002', 'total' => 500]);

        $response = $this->get('/orders?sort=total&direction=asc');

        $response->assertOk();
        $this->assertLessThan(strpos($response->getContent(), '#1002'), strpos($response->getContent(), '#1001'));
    }

    public function test_sidebar_links_to_the_orders_page(): void
    {
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee(route('orders.index'));
    }

    public function test_updates_endpoint_works_without_orders(): void
    {
        $this->getJson('/orders/updates')
            ->assertOk()
            ->assertJson(['changed' => false, 'latest_updated_at' => null]);
    }

    public function test_updates_endpoint_reports_new_orders_after_since(): void
    {
        $since = now()->subMinutes(5)->startOfSecond();
        $this->makeOrder(['number' => '#1001', 'updated_at' => $since->copy()->subSecond()]);

        // No newer orders yet.
        $this->getJson('/orders/updates?since='.rawurlencode($since->toIso8601String()))
            ->assertOk()
            ->assertJson(['changed' => false]);

        // A new order arriving after the "since" timestamp must flag a change.
        $this->makeOrder(['number' => '#1002']);

        $this->getJson('/orders/updates?since='.rawurlencode($since->toIso8601String()))
            ->assertOk()
            ->assertJson(['changed' => true]);
    }

    public function test_rows_endpoint_returns_new_order_rows(): void
    {
        $since = now()->subMinutes(5)->startOfSecond();
        $this->makeOrder(['number' => '#1001', 'updated_at' => $since->copy()->subSecond()]);

        $this->makeOrder(['number' => '#1002', 'shopify_id' => 'gid://shopify/Order/42']);

        $response = $this->getJson('/orders/rows?since='.rawurlencode($since->toIso8601String()));

        $response->assertOk();

        $rows = $response->json('rows');
        $this->assertCount(1, $rows);
        $this->assertSame('gid://shopify/Order/42', $rows[0]['shopify_id']);
        $this->assertStringContainsString('#1002', $rows[0]['html']);
    }
}
