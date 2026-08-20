<?php

namespace Tests\Feature;

use App\Services\Shopify\ShopifyClient;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopifyClientTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        config()->set('shopify.shop', 'test-store');
        config()->set('shopify.client_id', 'client-id');
        config()->set('shopify.client_secret', 'client-secret');
        config()->set('shopify.api_version', '2026-07');

        Cache::flush();
    }

    public function test_it_requests_an_access_token_using_the_client_credentials_grant(): void
    {
        Http::fake([
            'https://test-store.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_test',
                'scope' => 'read_products',
                'expires_in' => 86399,
            ]),
        ]);

        $token = app(ShopifyClient::class)->accessToken();

        $this->assertSame('shpat_test', $token);

        Http::assertSent(fn ($request) => $request->url() === 'https://test-store.myshopify.com/admin/oauth/access_token'
            && $request->method() === 'POST'
            && $request->hasHeader('Content-Type', 'application/x-www-form-urlencoded')
            && $request['grant_type'] === 'client_credentials'
            && $request['client_id'] === 'client-id'
            && $request['client_secret'] === 'client-secret');
    }

    public function test_it_caches_the_access_token(): void
    {
        Http::fake([
            'https://test-store.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_test',
                'expires_in' => 86399,
            ]),
        ]);

        $client = app(ShopifyClient::class);

        $client->accessToken();
        $client->accessToken();

        Http::assertSentCount(1);
    }

    public function test_it_attaches_the_token_header_to_graphql_requests(): void
    {
        Http::fake([
            'https://test-store.myshopify.com/admin/oauth/access_token' => Http::response([
                'access_token' => 'shpat_test',
                'expires_in' => 86399,
            ]),
            'https://test-store.myshopify.com/admin/api/2026-07/graphql.json' => Http::response([
                'data' => ['shop' => ['name' => 'Test Store']],
            ]),
        ]);

        $data = app(ShopifyClient::class)->graphql('{ shop { name } }');

        $this->assertSame('Test Store', $data['shop']['name']);

        Http::assertSent(fn ($request) => $request->url() === 'https://test-store.myshopify.com/admin/api/2026-07/graphql.json'
            && $request->hasHeader('X-Shopify-Access-Token', 'shpat_test')
            && $request['query'] === '{ shop { name } }');
    }
}
