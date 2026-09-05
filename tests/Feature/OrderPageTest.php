<?php

namespace Tests\Feature;

use App\Enums\Courier\ShipmentStatus;
use App\Models\CourierProvider;
use App\Models\Customer;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\User;
use Database\Seeders\CourierProvidersSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderPageTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

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

    public function test_order_rows_do_not_render_selection_checkboxes(): void
    {
        $this->makeOrder(['number' => '#1001']);

        $this->get('/orders')
            ->assertOk()
            ->assertSee('#1001')
            ->assertDontSee('aria-label="Select all orders"')
            ->assertDontSee('aria-label="Select #1001"');
    }

    public function test_filter_popup_stays_closed_when_filters_are_active(): void
    {
        $this->makeOrder(['number' => '#FILT-1']);

        $response = $this->get('/orders?q=FILT');

        $response->assertOk()
            ->assertSee('#FILT-1')
            // The filter badge is still visible so the user knows filters apply…
            ->assertSee('Filter')
            // …but the popup must stay closed until the Filter button is pressed.
            ->assertDontSee('<details class="relative" open>', false);
    }

    public function test_updates_endpoint_respects_active_filters(): void
    {
        $since = now()->subMinutes(5)->startOfSecond();

        $matching = $this->makeOrder(['number' => '#AAA-1', 'updated_at' => $since->copy()->subSecond()]);
        $this->makeOrder(['number' => '#BBB-1', 'updated_at' => now()]);

        // Only a non-matching order changed after "since" — the filtered
        // poll must NOT report a change, otherwise unrelated rows get
        // injected into the search results.
        $this->getJson('/orders/updates?since='.rawurlencode($since->toIso8601String()).'&q=AAA')
            ->assertOk()
            ->assertJson(['changed' => false]);

        // Now a matching order changes — the poll must flag it.
        $matching->forceFill(['updated_at' => now()])->save();

        $this->getJson('/orders/updates?since='.rawurlencode($since->toIso8601String()).'&q=AAA')
            ->assertOk()
            ->assertJson(['changed' => true]);
    }

    public function test_rows_endpoint_respects_active_filters(): void
    {
        $since = now()->subMinutes(5)->startOfSecond();

        $this->makeOrder(['number' => '#BBB-2', 'updated_at' => now()]);
        $this->makeOrder(['number' => '#AAA-2', 'shopify_id' => 'gid://shopify/Order/77', 'updated_at' => now()]);

        $response = $this->getJson('/orders/rows?since='.rawurlencode($since->toIso8601String()).'&q=AAA');

        $response->assertOk();
        $rows = $response->json('rows');
        $this->assertCount(1, $rows);
        $this->assertSame('gid://shopify/Order/77', $rows[0]['shopify_id']);
        $this->assertStringContainsString('#AAA-2', $rows[0]['html']);
        $this->assertStringNotContainsString('#BBB-2', $rows[0]['html']);
    }

    public function test_orders_page_supports_choosing_rows_per_page(): void
    {
        foreach (range(1, 15) as $i) {
            $this->makeOrder(['number' => "#P{$i}"]);
        }

        $this->get('/orders?per_page=10')
            ->assertOk()
            ->assertViewHas('orders', fn ($orders) => $orders->perPage() === 10)
            ->assertSee('Rows per page');

        // An invalid per_page value falls back to the default of 20.
        $this->get('/orders?per_page=999')
            ->assertOk()
            ->assertViewHas('orders', fn ($orders) => $orders->perPage() === 20);
    }

    public function test_orders_without_tracking_still_show_the_add_tracking_quick_action(): void
    {
        $order = $this->makeOrder(['number' => '#TRACKME']);

        $this->get('/orders')
            ->assertOk()
            ->assertSee('#TRACKME')
            ->assertSee('Add tracking / assign')
            ->assertSee(route('orders.add-tracking', $order));
    }

    public function test_orders_that_already_have_a_tracking_number_hide_the_add_tracking_button(): void
    {
        $this->seed(CourierProvidersSeeder::class);

        $order = $this->makeOrder(['number' => '#TRACKED']);

        $manual = CourierProvider::query()->where('key', 'manual')->firstOrFail();

        Shipment::create([
            'courier_provider_id' => $manual->id,
            'external_id' => 'MANUAL-'.strtoupper(bin2hex(random_bytes(4))),
            'tracking_number' => 'MNP-1000',
            'order_id' => $order->id,
            'status' => ShipmentStatus::Created->value,
            'currency' => 'PKR',
            'shipped_at' => now(),
            'last_event_at' => now(),
        ]);

        $this->get('/orders')
            ->assertOk()
            ->assertSee('#TRACKED')
            // The tracking column is still shown exactly as before.
            ->assertSee('MNP-1000')
            // The "Add tracking" quick action is gone for this order.
            ->assertDontSee('Add tracking / assign');
    }

    public function test_shopify_order_number_links_to_the_shopify_admin_order_page(): void
    {
        config()->set('shopify.shop', 'test-store');

        $this->makeOrder(['number' => '#LINKED', 'shopify_id' => 'gid://shopify/Order/123456789']);

        $this->get('/orders')
            ->assertOk()
            ->assertSee('#LINKED')
            ->assertSee('https://admin.shopify.com/store/test-store/orders/123456789', false)
            ->assertSee('Open order in Shopify admin', false);
    }

    public function test_local_order_without_a_shopify_id_stays_plain_text(): void
    {
        config()->set('shopify.shop', 'test-store');

        $this->makeOrder(['number' => '#LOCAL']);

        $this->get('/orders')
            ->assertOk()
            ->assertSee('#LOCAL')
            ->assertDontSee('admin.shopify.com');
    }

    public function test_add_tracking_quick_action_pushes_the_number_to_shopify(): void
    {
        $this->seed(CourierProvidersSeeder::class);

        config()->set('shopify.shop', 'test-store');
        config()->set('shopify.client_id', 'client-id');
        config()->set('shopify.client_secret', 'client-secret');
        config()->set('shopify.api_version', '2026-07');

        Http::fake([
            '*/admin/oauth/access_token' => Http::response(['access_token' => 'shpat_test', 'expires_in' => 3600], 200),
            '*/graphql.json' => function (Request $request) {
                $query = (string) ($request['query'] ?? '');

                if (str_contains($query, 'ShopifyOrderFulfillmentState')) {
                    return Http::response(['data' => [
                        'order' => [
                            'id' => 'gid://shopify/Order/77',
                            'fulfillments' => [],
                            'fulfillmentOrders' => ['edges' => [['node' => [
                                'id' => 'gid://shopify/FulfillmentOrder/1',
                            ]]]],
                        ],
                    ]], 200);
                }

                return Http::response(['data' => ['fulfillmentCreate' => ['userErrors' => []]]], 200);
            },
        ]);

        $order = $this->makeOrder(['number' => '#TRACKIT', 'shopify_id' => 'gid://shopify/Order/77']);

        $this->post(route('orders.add-tracking', $order), [
            'tracking_number' => 'LP-77',
            'carrier_name' => 'Leopards',
        ])->assertRedirect();

        Http::assertSent(function (Request $request) {
            $payload = json_decode((string) $request->body(), true);
            $fulfillment = $payload['variables']['fulfillment'] ?? [];

            return str_contains((string) $request->url(), '/graphql.json')
                && str_contains((string) ($payload['query'] ?? ''), 'fulfillmentCreate')
                && ($fulfillment['trackingInfo']['number'] ?? null) === 'LP-77';
        });
    }
}
