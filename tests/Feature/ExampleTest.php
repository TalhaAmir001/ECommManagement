<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A guest is funneled to the sign-in page for every dashboard area.
     */
    public function test_guests_are_sent_to_the_login_page(): void
    {
        $this->get('/')->assertRedirect(route('login'));
    }

    /**
     * An authenticated operator can reach the dashboard.
     */
    public function test_the_dashboard_returns_a_successful_response(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/dashboard')
            ->assertStatus(200);
    }
}

