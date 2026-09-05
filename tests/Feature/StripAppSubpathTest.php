<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\URL;
use Tests\TestCase;

class StripAppSubpathTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // These pages are behind the auth middleware now, so sign in as an
        // operator to observe the rendered content the stripping produced.
        $this->actingAs(User::factory()->create());
    }

    public function test_it_is_a_noop_when_app_url_has_no_subpath(): void
    {
        // APP_URL is forced to http://localhost in phpunit.xml, so there
        // is no subpath to strip — the route should resolve exactly as
        // registered.
        config()->set('app.url', 'http://localhost');

        $this->get('/dashboard')
            ->assertOk()
            ->assertSee('Dashboard');
    }

    public function test_it_strips_the_subpath_before_routing(): void
    {
        // Simulate a subdirectory install: APP_URL carries the prefix.
        config()->set('app.url', 'http://localhost/ECommManagement');

        // The URL generator is forced to APP_URL, so a request URL of
        // "/ECommManagement/dashboard" is what the test framework sends
        // out. The middleware should strip the prefix so the route
        // "/dashboard" still matches.
        $this->get('/ECommManagement/dashboard')
            ->assertOk()
            ->assertSee('Dashboard');
    }

    public function test_it_strips_the_subpath_for_the_root_path(): void
    {
        config()->set('app.url', 'http://localhost/ECommManagement');

        // "/ECommManagement" should become "/" so the redirect-to-
        // dashboard route fires.
        $this->get('/ECommManagement')
            ->assertRedirect(route('dashboard'));
    }

    public function test_it_preserves_the_query_string(): void
    {
        config()->set('app.url', 'http://localhost/ECommManagement');

        // The orders page accepts a "q" query param. The middleware
        // should strip the prefix but pass the query through untouched
        // — the controller's filter logic reads it via $request->query().
        $this->get('/ECommManagement/orders?q=anything')
            ->assertOk()
            // The "search" input is pre-filled from $request->query('q'),
            // so seeing the value rendered back to us confirms it made
            // it through the middleware.
            ->assertSee('value="anything"', false);
    }

    public function test_it_preserves_the_query_string_on_the_shipments_form(): void
    {
        config()->set('app.url', 'http://localhost/ECommManagement');

        // The "New shipment" button is a plain <a> tag that targets
        // /shipments?show_form=create. The view then renders the manual
        // shipment form when request('show_form') === 'create'. Symfony's
        // Request::create() unconditionally rewrites REQUEST_URI and
        // QUERY_STRING from the URI it parses, so a buggy middleware
        // that only passes the path will silently drop the query and
        // the form never appears.
        $this->get('/ECommManagement/shipments?show_form=create')
            ->assertOk()
            ->assertSee('New manual shipment')
            ->assertSee('name="tracking_number"', false)
            ->assertSee('Create shipment');
    }

    public function test_generated_urls_still_include_the_subpath(): void
    {
        config()->set('app.url', 'http://localhost/ECommManagement');
        URL::forceRootUrl(config('app.url'));

        // Pass a full URL. The test framework's SymfonyRequest::create()
        // parses out the path as /ECommManagement/dashboard — what the
        // browser would actually send — while the URL generator keeps
        // the subpath prefix for output links.
        $response = $this->call('GET', 'http://localhost/ECommManagement/dashboard');
        $response->assertOk();
        $html = (string) $response->getContent();

        // With URL::forceRootUrl in effect, every generated link in the
        // dashboard's nav should include the subpath.
        $this->assertStringContainsString('http://localhost/ECommManagement/', $html);
    }
}
