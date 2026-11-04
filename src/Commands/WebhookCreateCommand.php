<?php

declare(strict_types=1);

namespace WeBRTeu\Stripe\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Config;
use WeBRTeu\Stripe\StripeClient;

class WebhookCreateCommand extends Command
{
    protected $signature = 'stripe:webhook:create {--url= : URL-ul webhook-ului} {--events=* : Evenimentele (implicit din config)}';

    protected $description = 'Creează un endpoint de webhook Stripe și afișează secretul whsec_ o singură dată (nu îl scrie în fișiere)';

    public function handle(StripeClient $client): int
    {
        $url = $this->option('url');
        $events = (array) $this->option('events');

        $json = $client->send('POST', '/webhook_endpoints', [
            'url' => is_string($url) && $url !== '' ? $url : route('payments.webhook', ['gateway' => 'stripe']),
            'enabled_events' => $events !== [] ? array_values($events) : Config::array('stripe.webhook_events', []),
            'api_version' => Config::string('stripe.api_version', '2025-03-31.basil'),
            'description' => 'webrteu/payments',
        ]);

        $this->info('Webhook creat: '.(is_scalar($json['id'] ?? null) ? (string) $json['id'] : '?'));
        $this->warn('Pune secretul în STRIPE_WEBHOOK_SECRET (nu va mai fi afișat): '.(is_scalar($json['secret'] ?? null) ? (string) $json['secret'] : ''));

        return self::SUCCESS;
    }
}
