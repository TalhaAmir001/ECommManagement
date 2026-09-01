<?php

namespace App\Providers;

use App\Models\Shipment;
use App\Observers\ShipmentObserver;
use App\Services\Courier\CourierProviderRegistry;
use App\Services\Courier\OrderAutoMatcher;
use App\Services\Courier\OrderLookup;
use App\Services\Courier\TrackingLinkResolver;
use App\Services\Courier\WebTracking\GenericWebTracker;
use App\Services\Courier\WebTracking\StatusTextMapper;
use App\Services\Courier\WebTracking\TrackingUrlProbe;
use App\Services\Shopify\ShopifyClient;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ShopifyClient::class);
        $this->app->singleton(CourierProviderRegistry::class);

        // Build the OrderAutoMatcher from config so its policy lives in
        // config/couriers.php, not in code.
        $this->app->singleton(OrderAutoMatcher::class, function ($app) {
            $cfg = $app['config']->get('couriers.auto_match', []);

            return new OrderAutoMatcher(
                strategies: (array) ($cfg['strategies'] ?? ['reference', 'phone']),
                overwriteManual: (bool) ($cfg['overwrite_manual'] ?? false),
            );
        });

        // The status text mapper is stateless — one instance for the app.
        $this->app->singleton(StatusTextMapper::class);

        // OrderLookup is the single place that knows "how do we find a
        // plausible order to link this shipment to" — used by both the
        // AJAX typeahead and the new-shipment form, and by the auto-
        // matcher-style fallbacks. Stateless, so one instance.
        $this->app->singleton(OrderLookup::class);

        // The URL probe is driven by the configured known-hosts map so the
        // registry of "is this URL a courier we recognize" stays in config.
        $this->app->singleton(TrackingUrlProbe::class, function ($app) {
            return new TrackingUrlProbe(
                knownHosts: (array) $app['config']->get('couriers.tracking_hosts', []),
            );
        });

        // The generic HTML tracker is shared and uses the configured timeout
        // for outbound requests.
        $this->app->singleton(GenericWebTracker::class, function ($app) {
            return new GenericWebTracker(
                statusMapper: $app->make(StatusTextMapper::class),
                timeoutSeconds: (int) $app['config']->get('couriers.link_refresh.http_timeout_seconds', 20),
            );
        });

        // The link resolver is the thing everything else injects. It chains
        // a structured API provider when one is configured for the URL's
        // hostname, otherwise hands off to the generic web tracker.
        $this->app->singleton(TrackingLinkResolver::class, function ($app) {
            return new TrackingLinkResolver(
                probe: $app->make(TrackingUrlProbe::class),
                webTracker: $app->make(GenericWebTracker::class),
                registry: $app->make(CourierProviderRegistry::class),
                knownHosts: (array) $app['config']->get('couriers.tracking_hosts', []),
                providerKeysWithStructuredApi: (array) $app['config']->get('couriers.provider_keys_with_structured_api', []),
            );
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Force the URL generator to honor APP_URL — by default it would
        // use request()->root() (just host + scheme) and silently drop
        // the subpath this project lives at on XAMPP. Tests override
        // APP_URL to http://localhost in phpunit.xml, so they still see
        // routes rooted at "/" without needing per-test workarounds.
        $appUrl = (string) config('app.url');
        if ($appUrl !== '') {
            URL::forceRootUrl($appUrl);
        }

        // Wire the observer that propagates shipment status up to the
        // linked order's fulfillment / lifecycle status. One place, so
        // every write path (controller, jobs, resolvers) is covered.
        Shipment::observe(ShipmentObserver::class);
    }
}
