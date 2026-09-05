<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshDatabase;

    private function withoutCsrf(): void
    {
        $this->withoutMiddleware(ValidateCsrfToken::class);
    }

    public function test_guests_are_redirected_to_login_for_protected_pages(): void
    {
        foreach (['/dashboard', '/orders', '/shipments'] as $path) {
            $this->get($path)->assertRedirect(route('login'));
        }
    }

    public function test_the_login_page_loads_for_guests(): void
    {
        $this->get(route('login'))
            ->assertOk()
            ->assertSee('Sign in')
            ->assertSee('Welcome to Storefront');
    }

    public function test_authenticated_users_are_redirected_away_from_the_login_page(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->get(route('login'))
            ->assertRedirect(route('dashboard'));
    }

    public function test_a_user_can_log_in_with_the_env_credentials(): void
    {
        $this->withoutCsrf();

        config()->set('admin.email', 'admin@example.com');
        config()->set('admin.password', 'secret-password');
        config()->set('admin.name', 'Ops User');

        $this->post(route('login'), [
            'email' => 'admin@example.com',
            'password' => 'secret-password',
        ])->assertRedirect(route('dashboard'));

        $this->assertAuthenticated();

        // The operator identity should have been mirrored into users.
        $user = User::where('email', 'admin@example.com')->first();
        $this->assertNotNull($user);
        $this->assertSame('Ops User', $user->name);
    }

    public function test_a_user_cannot_log_in_with_wrong_credentials(): void
    {
        $this->withoutCsrf();

        config()->set('admin.email', 'admin@example.com');
        config()->set('admin.password', 'correct-horse');

        $this->from(route('login'))
            ->post(route('login'), [
                'email' => 'admin@example.com',
                'password' => 'wrong-password',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_an_unknown_operator_email_gets_the_generic_error(): void
    {
        $this->withoutCsrf();

        config()->set('admin.email', 'admin@example.com');
        config()->set('admin.password', 'secret-password');

        $this->from(route('login'))
            ->post(route('login'), [
                'email' => 'other@example.com',
                'password' => 'secret-password',
            ])
            ->assertRedirect(route('login'))
            ->assertSessionHasErrors('email');

        $this->assertGuest();
    }

    public function test_a_user_can_log_out(): void
    {
        $this->withoutCsrf();

        $user = User::factory()->create();

        $this->actingAs($user)
            ->post(route('logout'))
            ->assertRedirect(route('login'));

        $this->assertGuest();
    }
}
