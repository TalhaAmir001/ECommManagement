<?php

namespace App\Jobs;

use App\Services\Shopify\ShopifyClient;
use App\Services\Shopify\ShopifySync;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class ProcessShopifyOrderWebhook implements ShouldQueue
{
    use Queueable;

    public int $tries = 5;

    public array $backoff = [10, 30, 60, 300, 900];

    public function __construct(
        public string $shopifyOrderId,
        public string $topic,
    ) {
        $this->queue = 'shopify-webhooks';
    }

    public function handle(ShopifyClient $client, ShopifySync $sync): void
    {
        // Webhook payloads carry the numeric order id, but the GraphQL API and
        // the local database use the global ID form (gid://shopify/Order/<id>).
        $this->shopifyOrderId = $client->orderIdToGid($this->shopifyOrderId);

        if ($this->topic === 'orders/delete') {
            $this->handleDelete();
            return;
        }

        $order = $client->orderById($this->shopifyOrderId);

        if (empty($order['id'])) {
            Log::warning('ProcessShopifyOrderWebhook: Order not found in Shopify', [
                'shopify_id' => $this->shopifyOrderId,
                'topic' => $this->topic,
            ]);
            return;
        }

        $sync->upsertOrder($order);

        Log::info('ProcessShopifyOrderWebhook: Order upserted', [
            'shopify_id' => $this->shopifyOrderId,
            'topic' => $this->topic,
            'order_name' => $order['name'] ?? null,
        ]);
    }

    private function handleDelete(): void
    {
        $order = \App\Models\Order::where('shopify_id', $this->shopifyOrderId)->first();

        if ($order === null) {
            Log::warning('ProcessShopifyOrderWebhook: Delete received but order not found locally', [
                'shopify_id' => $this->shopifyOrderId,
            ]);
            return;
        }

        $order->status = 'cancelled';
        $order->save();

        Log::info('ProcessShopifyOrderWebhook: Order marked cancelled on delete', [
            'shopify_id' => $this->shopifyOrderId,
            'order_id' => $order->id,
        ]);
    }
}
