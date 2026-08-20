<?php

namespace App\Services\Shopify;

use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use RuntimeException;

class ShopifyClient
{
    /**
     * Cache key used to store the current access token.
     */
    private const TOKEN_CACHE_KEY = 'shopify.access_token';

    /**
     * Seconds to subtract from the token lifetime before treating it as
     * expired, so requests never run with a token in its final moments.
     */
    private const TOKEN_EXPIRY_BUFFER_SECONDS = 60;

    /**
     * Get the cache key for the current store's access token, scoped per shop
     * so a token is never reused across stores or configuration changes.
     */
    private function tokenCacheKey(): string
    {
        return self::TOKEN_CACHE_KEY.':'.$this->shop();
    }

    /**
     * Retrieve a valid Admin API access token, requesting a fresh one from
     * Shopify only when the cached token is missing or about to expire.
     *
     * @throws RuntimeException
     */
    public function accessToken(): string
    {
        $cached = Cache::get($this->tokenCacheKey());

        if (is_string($cached) && $cached !== '') {
            return $cached;
        }

        $response = Http::asForm()
            ->post($this->tokenUrl(), [
                'grant_type' => 'client_credentials',
                'client_id' => config('shopify.client_id'),
                'client_secret' => config('shopify.client_secret'),
            ]);

        $this->throwUnlessSuccessful($response, 'Unable to retrieve a Shopify access token.');

        $accessToken = $response->json('access_token');

        if (! is_string($accessToken) || $accessToken === '') {
            throw new RuntimeException('Shopify access token response did not include a valid access_token.');
        }

        $expiresIn = (int) $response->json('expires_in', 0);
        $ttl = max($expiresIn - self::TOKEN_EXPIRY_BUFFER_SECONDS, 60);

        Cache::put($this->tokenCacheKey(), $accessToken, $ttl);

        return $accessToken;
    }

    /**
     * Execute a GraphQL Admin API query and return the "data" portion.
     *
     * @param  array<string, mixed>  $variables
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    public function graphql(string $query, array $variables = []): array
    {
        $response = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->accessToken(),
        ])->post($this->graphqlUrl(), [
            'query' => $query,
            'variables' => (object) $variables,
        ]);

        $this->throwUnlessSuccessful($response, 'Shopify GraphQL request failed.');

        $payload = $response->json();

        if (! empty($payload['errors'])) {
            throw new RuntimeException('Shopify GraphQL returned errors: '.json_encode($payload['errors']));
        }

        return $payload['data'] ?? [];
    }

    /**
     * Execute a request against the Admin REST API and return the JSON body.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     *
     * @throws RuntimeException
     */
    public function rest(string $method, string $path, array $data = []): array
    {
        $request = Http::withHeaders([
            'X-Shopify-Access-Token' => $this->accessToken(),
        ]);

        $response = match (strtolower($method)) {
            'get' => $request->get($this->restUrl($path), $data),
            'post' => $request->post($this->restUrl($path), $data),
            'put' => $request->put($this->restUrl($path), $data),
            'delete' => $request->delete($this->restUrl($path), $data),
            default => throw new RuntimeException("Unsupported HTTP method [{$method}] for a Shopify REST request."),
        };

        $this->throwUnlessSuccessful($response, 'Shopify REST request failed.');

        return $response->json() ?? [];
    }

    /**
     * The configured store's myshopify.com subdomain.
     *
     * @throws RuntimeException
     */
    public function shop(): string
    {
        $shop = (string) config('shopify.shop');

        if ($shop === '') {
            throw new RuntimeException('SHOPIFY_SHOP is not configured.');
        }

        return $shop;
    }

    /**
     * The Admin API version in use.
     */
    public function apiVersion(): string
    {
        return (string) config('shopify.api_version', '2026-07');
    }

    /**
     * OAuth token endpoint for the client credentials grant.
     */
    public function tokenUrl(): string
    {
        return "https://{$this->shop()}.myshopify.com/admin/oauth/access_token";
    }

    /**
     * Versioned GraphQL Admin API endpoint.
     */
    public function graphqlUrl(): string
    {
        return "https://{$this->shop()}.myshopify.com/admin/api/{$this->apiVersion()}/graphql.json";
    }

    /**
     * Versioned Admin REST API endpoint for the given resource path.
     */
    public function restUrl(string $path): string
    {
        return "https://{$this->shop()}.myshopify.com/admin/api/{$this->apiVersion()}/{$path}.json";
    }

    /**
     * Normalize an order identifier to Shopify's global ID form. Accepts either
     * the numeric order id (as found in webhook payloads and REST responses) or
     * a gid:// ID and leaves valid global IDs untouched.
     */
    public function orderIdToGid(string $id): string
    {
        if (str_starts_with($id, 'gid://')) {
            return $id;
        }

        return 'gid://shopify/Order/'.$id;
    }

    /**
     * Fetch a single order by its Shopify order ID.
     *
     * @return array<string, mixed>
     */
    public function orderById(string $id): array
    {
        $data = $this->graphql(<<<'GRAPHQL'
            query ShopifyOrder($id: ID!) {
                order(id: $id) {
                    id
                    name
                    createdAt
                    displayFinancialStatus
                    displayFulfillmentStatus
                    totalPriceSet {
                        shopMoney { amount }
                    }
                    customer {
                        id
                        displayName
                        email
                        createdAt
                        defaultAddress { country }
                    }
                    lineItems(first: 250) {
                        edges {
                            node {
                                id
                                quantity
                                title
                                originalUnitPriceSet {
                                    shopMoney { amount }
                                }
                                variant {
                                    id
                                    sku
                                    title
                                    price
                                    product { id title productType }
                                }
                            }
                        }
                    }
                }
            }
            GRAPHQL, ['id' => $this->orderIdToGid($id)]);

        return $data['order'] ?? [];
    }

    /**
     * Throw a RuntimeException when an HTTP response is not successful.
     *
     * @throws RuntimeException
     */
    private function throwUnlessSuccessful(Response $response, string $message): void
    {
        if ($response->successful()) {
            return;
        }

        throw new RuntimeException(sprintf('%s (HTTP %d)', $message, $response->status()));
    }
}
