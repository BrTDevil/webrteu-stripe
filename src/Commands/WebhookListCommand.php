<?php

declare(strict_types=1);

namespace WeBRTeu\Stripe\Commands;

use Illuminate\Console\Command;
use WeBRTeu\Stripe\StripeClient;

class WebhookListCommand extends Command
{
    protected $signature = 'stripe:webhook:list';

    protected $description = 'Afișează endpoint-urile de webhook Stripe ale contului';

    public function handle(StripeClient $client): int
    {
        $json = $client->send('GET', '/webhook_endpoints', ['limit' => 100]);
        $rows = [];

        foreach ((array) ($json['data'] ?? []) as $endpoint) {
            if (is_array($endpoint)) {
                $rows[] = [
                    is_scalar($endpoint['id'] ?? null) ? (string) $endpoint['id'] : '',
                    is_scalar($endpoint['url'] ?? null) ? (string) $endpoint['url'] : '',
                    is_scalar($endpoint['status'] ?? null) ? (string) $endpoint['status'] : '',
                    implode(', ', array_map(strval(...), array_filter((array) ($endpoint['enabled_events'] ?? []), is_scalar(...)))),
                ];
            }
        }

        $this->table(['Id', 'URL', 'Status', 'Evenimente'], $rows);

        return self::SUCCESS;
    }
}
