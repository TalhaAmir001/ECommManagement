<?php

namespace App\Console\Commands;

use App\Services\Shopify\ShopifyClient;
use Illuminate\Console\Command;
use Throwable;

class ShopifyRegisterWebhook extends Command
{
    protected $signature = 'shopify:register-webhook
                            {--list : List all webhook subscriptions}
                            {--topic= : Topic to register (e.g. orders/create)}
                            {--delete= : Webhook subscription ID to delete}';

    protected $description = 'Register (all configured order topics by default), list, or delete Shopify webhook subscriptions';

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
            return $this->deleteWebhook((string) $this->option('delete'));
        }

        if ($this->option('topic')) {
            return $this->registerWebhook((string) $this->option('topic'));
        }

        return $this->registerAllConfigured();
    }

    /**
     * Register every topic listed in config/shopify.php (order_topics).
     * Idempotent — safe to re-run whenever the public callback URL changes
     * (e.g. after a tunnel is restarted and SHOPIFY_APP_URL is updated).
     */
    private function registerAllConfigured(): int
    {
        $topics = array_values(array_filter(
            array_map('trim', (array) config('shopify.order_topics', []))
        ));

        if ($topics === []) {
            $this->error('No order topics are configured. Add them under order_topics in config/shopify.php, or pass --topic=<topic>.');
            return self::FAILURE;
        }

        $failed = 0;

        foreach ($topics as $topic) {
            try {
                if ($this->registerWebhook((string) $topic) !== self::SUCCESS) {
                    $failed++;
                }
            } catch (Throwable $e) {
                $failed++;
                $this->error("[{$topic}] failed: {$e->getMessage()}");
            }
        }

        if ($failed > 0) {
            $this->error("{$failed} webhook topic(s) failed to register.");
            return self::FAILURE;
        }

        $this->info('All configured order webhooks are registered.');
        return self::SUCCESS;
    }

    /**
     * Fetch the current webhook subscriptions from Shopify.
     *
     * @return list<array<string, mixed>>
     */
    private function existingSubscriptions(): array
    {
        $query = 'query { webhookSubscriptions(first: 100) { edges { node { id topic callbackUrl createdAt } } } }';
        $data = $this->client->graphql($query);

        return $data['webhookSubscriptions']['edges'] ?? [];
    }

    private function listWebhooks(): int
    {
        $this->info('Fetching webhook subscriptions...');

        $subscriptions = $this->existingSubscriptions();

        if ($subscriptions === []) {
            $this->warn('No webhook subscriptions found.');
            return self::SUCCESS;
        }

        $this->table(
            ['ID', 'Topic', 'Callback URL', 'Created'],
            collect($subscriptions)->map(static fn (array $edge): array => [
                $edge['node']['id'],
                $edge['node']['topic'],
                $edge['node']['callbackUrl'],
                $edge['node']['createdAt'],
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

        // Shopify's GraphQL WebhookSubscriptionTopic enum uses UPPER_SNAKE_CASE
        // (e.g. ORDERS_CREATE), while topics are conventionally written as
        // REST-style resource/action (e.g. orders/create).
        $enumTopic = strtoupper(str_replace('/', '_', $topic));

        $this->info("Registering webhook for topic [{$topic}] at [{$callbackUrl}]...");

        // Idempotency: re-running after the callback URL changed (e.g. a new
        // tunnel URL) must replace the stale subscription instead of leaving a
        // duplicate that Shopify keeps sending to the dead address.
        $alreadyRegistered = false;

        foreach ($this->existingSubscriptions() as $edge) {
            $subscription = $edge['node'] ?? [];

            if (($subscription['topic'] ?? null) !== $enumTopic) {
                continue;
            }

            if (($subscription['callbackUrl'] ?? null) === $callbackUrl) {
                $alreadyRegistered = true;
                $this->info("Webhook [{$enumTopic}] is already registered at {$callbackUrl}.");
                continue;
            }

            $this->warn("Found stale webhook [{$enumTopic}] pointing at {$subscription['callbackUrl']}; deleting it so it can be re-registered...");
            $this->deleteWebhook((string) $subscription['id']);
        }

        if ($alreadyRegistered) {
            return self::SUCCESS;
        }

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
            'topic' => $enumTopic,
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
