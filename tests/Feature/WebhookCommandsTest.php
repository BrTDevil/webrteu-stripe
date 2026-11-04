<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

it('creates a webhook endpoint and prints the secret once, without writing files', function (): void {
    Http::fake([STRIPE.'/webhook_endpoints' => Http::response(fixtureFile('webhook-endpoint-created.json'))]);
    $env = base_path('.env');
    $before = is_file($env) ? (string) file_get_contents($env) : null;

    $this->artisan('stripe:webhook:create', ['--url' => 'https://shop.example/hook'])
        ->expectsOutputToContain('Webhook creat: we_1Q1test')
        ->expectsOutputToContain('whsec_created_once')
        ->assertSuccessful();

    expect(is_file($env) ? (string) file_get_contents($env) : null)->toBe($before);
    Http::assertSent(fn (Request $request): bool => $request['url'] === 'https://shop.example/hook'
        && in_array('checkout.session.completed', $request['enabled_events'], true)
        && $request['api_version'] === '2025-03-31.basil');
});

it('uses the package webhook route and custom events', function (): void {
    Http::fake([STRIPE.'/webhook_endpoints' => Http::response([])]);

    $this->artisan('stripe:webhook:create', ['--events' => ['charge.refunded']])->expectsOutputToContain('Webhook creat: ?');

    Http::assertSent(fn (Request $request): bool => $request['url'] === 'https://shop.example/webhooks/payments/stripe' && $request['enabled_events'] === ['charge.refunded']);
});

it('lists the webhook endpoints', function (): void {
    Http::fake([STRIPE.'/webhook_endpoints*' => Http::response(fixtureFile('webhook-endpoints.json'))]);

    $this->artisan('stripe:webhook:list')->expectsTable(['Id', 'URL', 'Status', 'Evenimente'], [
        ['we_1Q1test', 'https://shop.example/webhooks/payments/stripe', 'enabled', 'checkout.session.completed, charge.refunded'],
    ]);
});
