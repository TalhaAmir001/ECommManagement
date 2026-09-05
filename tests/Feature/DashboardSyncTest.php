<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Shopify\ShopifySync;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class DashboardSyncTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->actingAs(User::factory()->create());
    }

    public function test_dashboard_shows_the_sync_button_instead_of_export(): void
    {
        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Sync Shopify data')
            ->assertSee(route('dashboard.sync-shopify'), false)
            ->assertDontSee('Export');
    }

    public function test_sync_shopify_runs_the_sync_and_redirects_with_counts(): void
    {
        $mock = $this->createMock(ShopifySync::class);
        $mock->expects($this->once())
            ->method('syncAll')
            ->willReturn(['products' => 3, 'customers' => 2, 'orders' => 5]);

        $this->instance(ShopifySync::class, $mock);

        $this->post('/dashboard/sync-shopify')
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('status', 'Shopify sync complete — 3 products, 2 customers, 5 orders synced.');
    }

    public function test_sync_shopify_flashes_an_error_when_the_sync_fails(): void
    {
        $mock = $this->createMock(ShopifySync::class);
        $mock->method('syncAll')->willThrowException(new RuntimeException('simulated failure'));

        $this->instance(ShopifySync::class, $mock);

        $this->post('/dashboard/sync-shopify')
            ->assertRedirect(route('dashboard'))
            ->assertSessionHas('error');
    }
}