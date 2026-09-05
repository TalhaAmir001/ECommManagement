<?php

namespace Tests\Feature;

use App\Enums\Courier\ShipmentStatus;
use App\Models\CourierProvider;
use App\Models\Customer;
use App\Models\JournalAccount;
use App\Models\JournalCategory;
use App\Models\Order;
use App\Models\Product;
use App\Models\Shipment;
use App\Models\Vendor;
use App\Models\VendorPurchase;
use App\Services\JournalService;
use App\Services\ProfitAndLossService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProfitAndLossServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(\Database\Seeders\CourierProvidersSeeder::class);
        $this->seed(\Database\Seeders\JournalAccountsSeeder::class);
    }

    public function test_formula_waterfall(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['price' => 1000, 'cost' => 400, 'category' => 'Apparel']);

        // Online payment at checkout (PAID).
        $order = Order::factory()->create([
            'customer_id' => $customer->id,
            'number' => '#1000',
            'financial_status' => 'PAID',
            'status' => 'delivered',
            'total' => 1000,
            'created_at' => now(),
        ]);
        $order->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 1000,
        ]);

        // Delivered COD parcel: 1500 cash collected, 200 courier cost.
        $provider = CourierProvider::query()->where('key', 'manual')->firstOrFail();
        Shipment::query()->create([
            'courier_provider_id' => $provider->id,
            'external_id' => 'TST-PNL-COD-1',
            'tracking_number' => 'PNL-COD-1',
            'status' => ShipmentStatus::Delivered->value,
            'shipped_at' => now(),
            'delivered_at' => now(),
            'last_event_at' => now(),
            'cod_amount' => 1500,
            'cost' => 200,
            'currency' => 'PKR',
        ]);

        // Vendor owed (CoGS) = 400.
        $vendor = Vendor::query()->create(['name' => 'Supplier A']);
        VendorPurchase::query()->create([
            'vendor_id' => $vendor->id,
            'item_description' => 'Shirts',
            'quantity' => 1,
            'unit' => 'pcs',
            'unit_cost' => 400,
            'total_cost' => 400,
            'purchase_date' => now()->toDateString(),
            'currency' => 'PKR',
        ]);

        // One posted expense entry of 100.
        $expenseCategory = JournalCategory::query()->where('type', 'expense')->firstOrFail();
        $paymentAccount = JournalAccount::query()->paymentAccounts()->firstOrFail();
        app(JournalService::class)->createEntry([
            'entry_date' => now()->toDateString(),
            'direction' => 'expense',
            'amount' => 100,
            'category_id' => $expenseCategory->id,
            'payment_account_id' => $paymentAccount->id,
            'description' => 'Packaging',
            'status' => 'posted',
        ]);

        $totals = app(ProfitAndLossService::class)->totals();

        $this->assertSame(1000.0, $totals['online_payments']);
        $this->assertSame(1300.0, $totals['net_cod']);            // 1500 − 200
        $this->assertSame(2300.0, $totals['gross_sale']);         // 1000 + 1300
        $this->assertSame(0.0, $totals['returned_products']);
        $this->assertSame(2300.0, $totals['total_sale']);
        $this->assertSame(400.0, $totals['cogs_vendor']);
        $this->assertSame(1900.0, $totals['gross_profit']);
        $this->assertSame(0.0, $totals['shipping_received']);
        $this->assertSame(1900.0, $totals['total_profit']);
        $this->assertSame(0.0, $totals['courier_charges']);       // COD parcel cost is netted, not charged again
        $this->assertSame(1900.0, $totals['net_profit']);
        $this->assertSame(100.0, $totals['expenses']);
        $this->assertSame(0.0, $totals['other_income']);
        $this->assertSame(1800.0, $totals['profit_before_tax']);
        $this->assertSame(72.0, $totals['tax']);                  // 4% of 1800
        $this->assertSame(1728.0, $totals['total_net_profit']);
        $this->assertSame(75.1, $totals['margin']);               // 1728 / 2300
        $this->assertSame(2, $totals['orders']);                  // online order + delivered COD parcel

        // Monthly path must bucket the same money onto the current month.
        $monthKey = now()->format('Y-m');
        $monthly = collect(app(ProfitAndLossService::class)->monthly())->keyBy('key');
        $this->assertTrue($monthly->has($monthKey));
        $this->assertSame(2300.0, (float) $monthly[$monthKey]['gross_sale']);
        $this->assertSame(1728.0, (float) $monthly[$monthKey]['total_net_profit']);
    }

    public function test_returned_products_and_manual_shipping_income(): void
    {
        $customer = Customer::factory()->create();
        $product = Product::factory()->create(['price' => 500, 'cost' => 200, 'category' => 'Apparel']);

        // Refunded order subtracts from gross sale.
        $refunded = Order::factory()->create([
            'customer_id' => $customer->id,
            'number' => '#2000',
            'financial_status' => 'REFUNDED',
            'status' => 'delivered',
            'total' => 500,
            'created_at' => now(),
        ]);
        $refunded->items()->create([
            'product_id' => $product->id,
            'quantity' => 1,
            'unit_price' => 500,
        ]);

        // Manual, non-COD parcel: store charged customer 250 shipping (income)
        // and paid the courier 100 (expense via courier charges).
        $provider = CourierProvider::query()->where('key', 'manual')->firstOrFail();
        Shipment::query()->create([
            'courier_provider_id' => $provider->id,
            'external_id' => 'TST-PNL-MAN-1',
            'tracking_number' => 'PNL-MAN-1',
            'status' => ShipmentStatus::InTransit->value,
            'shipped_at' => now(),
            'last_event_at' => now(),
            'shipping_charged' => 250,
            'cost' => 100,
            'currency' => 'PKR',
        ]);

        $totals = app(ProfitAndLossService::class)->totals();

        $this->assertSame(0.0, $totals['online_payments']);
        $this->assertSame(0.0, $totals['net_cod']);
        $this->assertSame(500.0, $totals['returned_products']);
        $this->assertSame(-500.0, $totals['total_sale']);
        $this->assertSame(250.0, $totals['shipping_received']);
        $this->assertSame(100.0, $totals['courier_charges']);
        $this->assertSame(0.0, $totals['cogs_vendor']);
        // gross profit −500 + shipping 250 = −250 → net −250 − 100 = −350; tax 0.
        $this->assertSame(-350.0, $totals['net_profit']);
        $this->assertSame(0.0, $totals['tax']);
        $this->assertSame(-350.0, $totals['total_net_profit']);
    }
}

