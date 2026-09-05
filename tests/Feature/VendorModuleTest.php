<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Vendor;
use App\Models\VendorPayment;
use App\Models\VendorPurchase;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class VendorModuleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutMiddleware(ValidateCsrfToken::class);
        $this->actingAs(User::factory()->create());
    }

    public function test_the_vendor_list_renders_with_summary_and_search(): void
    {
        Vendor::factory()->create(['name' => 'Faisal Fabrics']);

        $this->get(route('vendors.index'))
            ->assertOk()
            ->assertSee('Vendors')
            ->assertSee('Faisal Fabrics')
            ->assertSee('Add vendor');
    }

    public function test_a_vendor_can_be_created_and_shown(): void
    {
        $this->post(route('vendors.store'), [
            'name' => 'Karachi Raw Materials',
            'contact_name' => 'Salman',
            'email' => 'salman@example.com',
            'phone' => '03001234567',
            'currency' => 'PKR',
        ])->assertRedirect();

        $vendor = Vendor::firstOrFail();
        $this->assertSame('Karachi Raw Materials', $vendor->name);

        $this->get(route('vendors.show', $vendor))
            ->assertOk()
            ->assertSee('Karachi Raw Materials')
            ->assertSee('No purchases recorded')
            ->assertSee('No payments recorded');
    }

    public function test_vendor_store_validates_required_name(): void
    {
        $this->post(route('vendors.store'), ['name' => ''])
            ->assertSessionHasErrors('name');

        $this->assertDatabaseCount('vendors', 0);
    }

    public function test_a_vendor_can_be_updated(): void
    {
        $vendor = Vendor::factory()->create(['name' => 'Old Name']);

        $this->put(route('vendors.update', $vendor), [
            'name' => 'New Name',
            'contact_name' => 'Ali',
        ])->assertRedirect(route('vendors.show', $vendor));

        $this->assertDatabaseHas('vendors', [
            'id' => $vendor->id,
            'name' => 'New Name',
        ]);
    }

    public function test_a_vendor_can_be_deleted_and_cascades_its_history(): void
    {
        $vendor = Vendor::factory()->create();
        $purchase = VendorPurchase::factory()->create(['vendor_id' => $vendor->id]);
        $payment = VendorPayment::factory()->create(['vendor_id' => $vendor->id]);

        $this->delete(route('vendors.destroy', $vendor))
            ->assertRedirect(route('vendors.index'));

        $this->assertDatabaseMissing('vendors', ['id' => $vendor->id]);
        $this->assertDatabaseMissing('vendor_purchases', ['id' => $purchase->id]);
        $this->assertDatabaseMissing('vendor_payments', ['id' => $payment->id]);
    }

    public function test_balance_is_purchases_minus_payments(): void
    {
        $vendor = Vendor::factory()->create();

        VendorPurchase::factory()->create(['vendor_id' => $vendor->id, 'total_cost' => 1000]);
        VendorPurchase::factory()->create(['vendor_id' => $vendor->id, 'total_cost' => 250.50]);
        VendorPayment::factory()->create(['vendor_id' => $vendor->id, 'amount' => 400]);

        $this->assertSame(1250.5, $vendor->fresh()->totalPurchased());
        $this->assertSame(400.0, $vendor->fresh()->totalPaid());
        $this->assertSame(850.5, $vendor->fresh()->balance());
    }

    public function test_recording_a_purchase_extends_the_owed_balance(): void
    {
        $vendor = Vendor::factory()->create();

        $this->post(route('vendors.purchases.store', $vendor), [
            'item_description' => 'Cotton fabric',
            'reference' => 'INV-9',
            'quantity' => 3,
            'unit' => 'roll',
            'unit_cost' => 1500,
            'purchase_date' => '2026-09-01',
        ])->assertRedirect();

        $purchase = $vendor->purchases()->firstOrFail();
        $this->assertSame(3.0, (float) $purchase->quantity);
        $this->assertSame(4500.0, (float) $purchase->total_cost);
        $this->assertSame(4500.0, $vendor->fresh()->balance());

        $this->get(route('vendors.show', $vendor))
            ->assertOk()
            ->assertSee('Cotton fabric')
            ->assertSee('INV-9');
    }

    public function test_recording_a_payment_reduces_the_owed_balance(): void
    {
        $vendor = Vendor::factory()->create();
        VendorPurchase::factory()->create(['vendor_id' => $vendor->id, 'total_cost' => 2000]);

        $this->post(route('vendors.payments.store', $vendor), [
            'amount' => 800,
            'payment_date' => '2026-09-02',
            'method' => 'Bank Transfer',
        ])->assertRedirect();

        $this->assertSame(800.0, (float) $vendor->fresh()->totalPaid());
        $this->assertSame(1200.0, $vendor->fresh()->balance());

        $this->get(route('vendors.show', $vendor))
            ->assertOk()
            ->assertSee('Bank Transfer');
    }

    public function test_purchase_and_payment_records_can_be_removed(): void
    {
        $vendor = Vendor::factory()->create();
        $purchase = VendorPurchase::factory()->create(['vendor_id' => $vendor->id, 'total_cost' => 900]);
        $payment = VendorPayment::factory()->create(['vendor_id' => $vendor->id, 'amount' => 100]);

        $this->delete(route('vendors.purchases.destroy', $purchase))->assertRedirect();
        $this->delete(route('vendors.payments.destroy', $payment))->assertRedirect();

        $this->assertDatabaseCount('vendor_purchases', 0);
        $this->assertDatabaseCount('vendor_payments', 0);
        $this->assertSame(0.0, $vendor->fresh()->balance());
    }

    public function test_purchase_store_validates_amounts(): void
    {
        $vendor = Vendor::factory()->create();

        $this->post(route('vendors.purchases.store', $vendor), [
            'item_description' => 'Thread',
            'quantity' => 0,
            'unit_cost' => 0,
            'purchase_date' => '2026-09-01',
        ])->assertSessionHasErrors(['quantity', 'unit_cost']);

        $this->assertDatabaseCount('vendor_purchases', 0);
    }

    public function test_vendor_pages_require_authentication(): void
    {
        $vendor = Vendor::factory()->create();

        $this->get(route('vendors.index'))->assertOk();

        auth()->logout();

        $this->get(route('vendors.index'))->assertRedirect(route('login'));
        $this->get(route('vendors.show', $vendor))->assertRedirect(route('login'));
    }
}
