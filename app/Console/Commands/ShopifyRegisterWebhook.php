<?php

namespace App\Console\Commands;

use App\Services\Shopify\ShopifyClient;
use Illuminate\Console\Command;

class ShopifyRegisterWebhook extends Command
{
    protected $signature = 'shopify:register-webhook
                            {--list : List all webhook subscriptions}
                            {--topic= : Topic to register (e.g. orders/create)}
                            {--delete= : Webhook subscription ID to delete}';

    protected $description = 'Register, list, or delete Shopify webhook subscriptions';

    public function __construct(private readonly ShopifyClient $client)
    {
        parent::__construct();
    }

    public function handle(): int
    {
        if ($this->option('list')) {
            return $this->listWebhooks();
        }

        if ($this->option('delete')) {
            return $this->deleteWebhook($this->option('delete'));
        }

        if ($this->option('topic')) {
            return $this->registerWebhook($this->option('topic'));
        }

        $this->error('Please use --list, --topic=<topic>, or --delete=<id>');
        return self::FAILURE;
    }

    private function listWebhooks(): int
    {
        $this->info('Fetching webhook subscriptions...');

        $query = 'query { webhookSubscriptions(first: 100) { edges { node { id topic callbackUrl createdAt } } } }';
        $data = $this->client->graphql($query);

        $subscriptions = $data['webhookSubscriptions']['edges'] ?? [];

        if (empty($subscriptions)) {
            $this->warn('No webhook subscriptions found.');
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Topic', 'Callback URL', 'Created'],
            collect($subscriptions)->map(fn($e) => [
                $e['node']['id'],
                $e['node']['topic'],
                $e['node']['callbackUrl'],
                $e['node']['createdAt'],
            ])
        );

        return self::SUCCESS;
    }

    private function registerWebhook(string $topic): int
    {
        $baseUrl = rtrim((string) config('shopify.webhook_base_url', ''), '/');

        if (empty($baseUrl)) {
            $this->error('SHOPIFY_APP_URL is not configured. Set it in .env');
            return self::FAILURE;
        }

        $callbackUrl = $baseUrl.'/webhooks/shopify/'.$topic;

        $this->info("Registering webhook for topic [{$topic}] at [{$callbackUrl}]...");

        $mutation = 'mutation webhookSubscriptionCreate($topic: WebhookSubscriptionTopic!, $callbackUrl: URL!) {'
            .' webhookSubscriptionCreate(topic: $topic, webhookSubscription: {'
            .' callbackUrl: $callbackUrl,'
            .' format: JSON'
            .' }) {'
            .' webhookSubscription { id topic callbackUrl createdAt }'
            .' userErrors { field message }'
            .' }'
            .'}';

        $data = $this->client->graphql($mutation, [
            // Shopify's GraphQL WebhookSubscriptionTopic enum uses UPPER_SNAKE_CASE
            // (e.g. ORDERS_CREATE), while topics are conventionally written as
            // REST-style resource/action (e.g. orders/create).
            'topic' => strtoupper(str_replace('/', '_', $topic)),
            'callbackUrl' => $callbackUrl,
        ]);

        $result = $data['webhookSubscriptionCreate'] ?? null;

        if (! empty($result['userErrors'])) {
            foreach ($result['userErrors'] as $error) {
                $this->error('Error: '.$error['message']);
            }
            return self::FAILURE;
        }

        $subscription = $result['webhookSubscription'] ?? null;

        if ($subscription === null) {
            $this->error('Failed to create webhook subscription.');
            return self::FAILURE;
        }

        $this->info('Webhook registered successfully!');
        $this->table(
            ['ID', 'Topic', 'Callback URL', 'Created'],
            [[
                $subscription['id'],
                $subscription['topic'],
                $subscription['callbackUrl'],
                $subscription['createdAt'],
            ]]
        );

        $this->warn('IMPORTANT: Save the webhook ID above to delete it later if needed.');
        $this->warn('IMPORTANT: Set SHOPIFY_WEBHOOK_SECRET in your .env to your app client secret (the same value as SHOPIFY_CLIENT_SECRET). Shopify signs webhook deliveries with the app client secret.');

        return self::SUCCESS;
    }

    private function deleteWebhook(string $id): int
    {
        $this->info("Deleting webhook subscription [{$id}]...");

        $mutation = 'mutation webhookSubscriptionDelete($id: ID!) {'
            .' webhookSubscriptionDelete(id: $id) {'
            .' deletedWebhookSubscriptionId'
            .' userErrors { field message }'
            .' }'
            .'}';

        $data = $this->client->graphql($mutation, ['id' => $id]);

        $result = $data['webhookSubscriptionDelete'] ?? null;

        if (! empty($result['userErrors'])) {
            foreach ($result['userErrors'] as $error) {
                $this->error('Error: '.$error['message']);
            }
            return self::FAILURE;
        }

        $deletedId = $result['deletedWebhookSubscriptionId'] ?? null;

        if ($deletedId) {
            $this->info("Webhook [{$deletedId}] deleted successfully.");
            return self::SUCCESS;
        }

        $this->error('Failed to delete webhook subscription.');
        return self::FAILURE;
    }
}
