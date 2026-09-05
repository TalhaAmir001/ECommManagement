<?php

namespace App\Services\Shopify;

/**
 * GraphQL documents shared between the batch sync (ShopifySync) and the
 * single-order webhook fetch (ShopifyClient::orderById).
 *
 * Keeping the order selection in one place means the two code paths can never
 * drift apart again — they already did once, which is how a fulfillments query
 * shaped like a connection reached production and broke the orders sync.
 */
final class ShopifyQueries
{
    /**
     * Shared Order selection used by the paged orders sync and orderById.
     *
     * Notes on the fulfillment block (Admin GraphQL 2026-07):
     *  - Order.fulfillments is a plain list, [Fulfillment!]!, NOT a Relay
     *    connection — there is no `edges`/`node` wrapper.
     *  - Fulfillment.trackingInfo is [FulfillmentTrackingInfo!]! (one entry
     *    per package), not a single object.
     *  - FulfillmentOriginAddress has no `name` field.
     */
    public const ORDER_FIELDS = <<<'GRAPHQL'
        fragment OrderFields on Order {
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
                defaultAddress {
                    address1
                    address2
                    city
                    province
                    zip
                    country
                    phone
                }
            }
            shippingAddress {
                name
                address1
                address2
                city
                province
                zip
                country
                phone
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
                            inventoryItem {
                                measurement {
                                    weight {
                                        value
                                        unit
                                    }
                                }
                            }
                            product { id title productType }
                        }
                    }
                }
            }
            fulfillments(first: 50) {
                id
                status
                displayStatus
                createdAt
                updatedAt
                deliveredAt
                estimatedDeliveryAt
                trackingInfo(first: 10) {
                    number
                    company
                    url
                }
                originAddress {
                    address1
                    address2
                    city
                    provinceCode
                    zip
                    countryCode
                }
            }
        }
        GRAPHQL;
}
