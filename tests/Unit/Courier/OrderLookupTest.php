<?php

namespace Tests\Unit\Courier;

use App\Models\Customer;
use App\Models\Order;
use App\Services\Courier\OrderLookup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class OrderLookupTest extends TestCase
{
    use RefreshDatabase;

    private function makeCustomer(array $attrs = []): Customer
    {
        return Customer::create(array_merge([
            'name' => 'Jane Doe',
            'email' => 'jane@example.com',
            'phone' => '03001234567',
            'country' => 'Pakistan',
        ], $attrs));
    }

    private function makeOrder(array $attrs = []): Order
    {
        return Order::create(array_merge([
            'number' => '#1001',
            'total' => 100.0,
            'financial_status' => 'PAID',
            'fulfillment_status' => 'UNFULFILLED',
        ], $attrs));
    }

    public function test_find_by_id_returns_null_for_garbage(): void
    {
        $lookup = new OrderLookup;

        $this->assertNull($lookup->findById(null));
        $this->assertNull($lookup->findById(''));
        $this->assertNull($lookup->findById('not-a-number'));
        $this->assertNull($lookup->findById(-1));
        $this->assertNull($lookup->findById(0));
        $this->assertNull($lookup->findById(99999));
    }

    public function test_find_by_id_returns_the_order(): void
    {
        $order = $this->makeOrder();
        $lookup = new OrderLookup;

        $found = $lookup->findById($order->id);
        $this->assertNotNull($found);
        $this->assertSame($order->id, $found->id);
    }

    public function test_find_by_number_finds_an_exact_match(): void
    {
        $this->makeOrder(['number' => '#1001']);
        $this->makeOrder(['number' => '#1002']);

        $lookup = new OrderLookup;

        $this->assertSame('#1001', $lookup->findByNumber('#1001')?->number);
        $this->assertSame('#1002', $lookup->findByNumber('#1002')?->number);
        $this->assertNull($lookup->findByNumber('#9999'));
        $this->assertNull($lookup->findByNumber(''));
    }

    public function test_suggest_matches_by_order_number(): void
    {
        $this->makeOrder(['number' => '#1234']);
        $this->makeOrder(['number' => '#5678']);

        $lookup = new OrderLookup;
        $results = $lookup->suggest('#123');

        $this->assertCount(1, $results);
        $this->assertSame('#1234', $results[0]['number']);
    }

    public function test_suggest_matches_by_customer_phone(): void
    {
        $customer = $this->makeCustomer(['phone' => '03001234567']);
        $this->makeOrder(['number' => '#1', 'customer_id' => $customer->id]);
        $this->makeOrder(['number' => '#2']);

        $lookup = new OrderLookup;
        $results = $lookup->suggest('1234567');

        $this->assertCount(1, $results);
        $this->assertSame('#1', $results[0]['number']);
    }

    public function test_suggest_returns_empty_for_blank_query(): void
    {
        $this->makeOrder();
        $lookup = new OrderLookup;

        $this->assertSame([], $lookup->suggest(''));
        $this->assertSame([], $lookup->suggest('   '));
    }

    public function test_suggest_caps_results(): void
    {
        // Create more orders than the cap.
        for ($i = 0; $i < OrderLookup::MAX_RESULTS + 5; $i++) {
            $this->makeOrder(['number' => sprintf('#%05d', $i)]);
        }

        $lookup = new OrderLookup;
        $results = $lookup->suggest('#');

        $this->assertCount(OrderLookup::MAX_RESULTS, $results);
    }

    public function test_suggest_excludes_a_specific_order(): void
    {
        $a = $this->makeOrder(['number' => '#1']);
        $b = $this->makeOrder(['number' => '#2']);

        $lookup = new OrderLookup;
        $results = $lookup->suggest('#', excludeId: $a->id);

        $this->assertCount(1, $results);
        $this->assertSame('#2', $results[0]['number']);
    }

    public function test_suggest_for_new_shipment_prefers_reference_match(): void
    {
        $this->makeOrder(['number' => '#1001']);

        $lookup = new OrderLookup;
        $results = $lookup->suggestForNewShipment(reference: '#1001', consigneePhone: null);

        $this->assertCount(1, $results);
        $this->assertSame('#1001', $results[0]['number']);
        $this->assertStringContainsString('reference matches', $results[0]['reason']);
    }

    public function test_suggest_for_new_shipment_falls_back_to_phone_match(): void
    {
        $customer = $this->makeCustomer(['phone' => '03001234567']);
        $this->makeOrder(['number' => '#5', 'customer_id' => $customer->id]);

        $lookup = new OrderLookup;
        $results = $lookup->suggestForNewShipment(reference: null, consigneePhone: '03001234567');

        $this->assertCount(1, $results);
        $this->assertSame('#5', $results[0]['number']);
        $this->assertStringContainsString('phone matches', $results[0]['reason']);
    }

    public function test_suggest_for_new_shipment_does_not_double_list(): void
    {
        $customer = $this->makeCustomer(['phone' => '03001234567']);
        $this->makeOrder(['number' => '#1001', 'customer_id' => $customer->id]);

        $lookup = new OrderLookup;
        $results = $lookup->suggestForNewShipment(reference: '#1001', consigneePhone: '03001234567');

        $this->assertCount(1, $results);
    }

    public function test_suggest_carries_cod_amount_for_unpaid_orders(): void
    {
        $this->makeOrder(['number' => '#COD1', 'total' => 2500.0, 'financial_status' => 'PENDING']);

        $results = (new OrderLookup)->suggest('#COD1');

        $this->assertCount(1, $results);
        $this->assertSame(2500.0, $results[0]['cod_amount']);
    }

    public function test_suggest_carries_null_cod_amount_for_paid_orders(): void
    {
        $this->makeOrder(['number' => '#PAID1', 'total' => 2500.0, 'financial_status' => 'PAID']);

        $results = (new OrderLookup)->suggest('#PAID1');

        $this->assertCount(1, $results);
        $this->assertNull($results[0]['cod_amount']);
    }

    public function test_suggest_prefers_shipping_phone_over_customer_phone(): void
    {
        $customer = $this->makeCustomer(['phone' => '03001234567']);
        $this->makeOrder(['number' => '#PHONE1', 'customer_id' => $customer->id, 'shipping_phone' => '03111234567']);

        $results = (new OrderLookup)->suggest('#PHONE1');

        $this->assertCount(1, $results);
        $this->assertSame('03111234567', $results[0]['consignee_phone']);
    }

    public function test_suggest_falls_back_to_customer_phone_when_no_shipping_phone(): void
    {
        $customer = $this->makeCustomer(['phone' => '03001234567']);
        $this->makeOrder(['number' => '#PHONE2', 'customer_id' => $customer->id]);

        $results = (new OrderLookup)->suggest('#PHONE2');

        $this->assertCount(1, $results);
        $this->assertSame('03001234567', $results[0]['consignee_phone']);
    }
}
