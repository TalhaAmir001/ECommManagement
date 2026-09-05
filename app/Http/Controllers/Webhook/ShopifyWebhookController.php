<?php

namespace App\Http\Controllers\Webhook;

use App\Http\Controllers\Controller;
use App\Jobs\ProcessShopifyOrderWebhook;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use RuntimeException;

class ShopifyWebhookController extends Controller
{
    public function handle(Request $request, string $topic): Response
    {
        $this->verifyHmac($request);

        $payload = $request->input();

        $orderId = $payload['id'] ?? null;

        if ($orderId === null) {
            Log::warning('ShopifyWebhookController: Received webhook without an order id', [
                'topic' => $topic,
            ]);

            return response()->noContent(400);
        }

        Log::info('ShopifyWebhookController: Webhook received', [
            'topic' => $topic,
            'order_id' => $orderId,
        ]);

        ProcessShopifyOrderWebhook::dispatch($orderId, $topic);

        return response()->noContent();
    }

    private function verifyHmac(Request $request): void
    {
        $secret = (string) config('shopify.webhook_secret');

        if ($secret === '') {
            throw new RuntimeException('SHOPIFY_WEBHOOK_SECRET is not configured.');
        }

        $provided = $request->header('X-Shopify-Hmac-Sha256', '');
        $body = $request->getContent();

        // Shopify base64-encodes the HMAC-SHA256 digest of the raw request body,
        // computed with the app client secret as the key.
        $expected = base64_encode(hash_hmac('sha256', $body, $secret, true));

        if (! hash_equals($expected, $provided)) {
            Log::warning('ShopifyWebhookController: Invalid webhook signature', [
                'topic' => (string) $request->route('topic'),
                'provided' => $provided,
                'expected' => $expected,
            ]);

            abort(401, 'Invalid webhook signature.');
        }
    }
}
