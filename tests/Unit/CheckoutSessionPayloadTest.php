<?php

declare(strict_types=1);

use WeBRTeu\Payments\Data\ItemData;
use WeBRTeu\Stripe\Requests\CheckoutSessionPayload;

it('builds a payment session with the amount in bani and the order reference', function (): void {
    expect(CheckoutSessionPayload::make(paymentRequest()))->toBe([
        'mode' => 'payment',
        'client_reference_id' => '123-1',
        'customer_email' => 'ion@example.com',
        'locale' => 'ro',
        'success_url' => 'https://shop.example/plata/stripe/return?order=123&session_id={CHECKOUT_SESSION_ID}',
        'cancel_url' => 'https://shop.example/plata/stripe/return?order=123',
        'line_items' => [[
            'price_data' => ['currency' => 'ron', 'unit_amount' => 14170, 'product_data' => ['name' => 'Comanda #123']],
            'quantity' => 1,
        ]],
        'metadata' => ['order_id' => '123-1'],
        'payment_intent_data' => ['description' => 'Comanda #123', 'metadata' => ['order_id' => '123-1']],
    ]);
});

it('uses manual capture, a separate cancel URL, fixed payment methods and auto locale', function (): void {
    $payload = CheckoutSessionPayload::make(paymentRequest(locale: 'xx'), 'manual', 'https://shop.example/cos', ['card']);

    expect($payload['payment_intent_data'])->toHaveKey('capture_method', 'manual')
        ->and($payload['cancel_url'])->toBe('https://shop.example/cos')
        ->and($payload['payment_method_types'])->toBe(['card'])
        ->and($payload['locale'])->toBe('auto');
});

it('sends the items when their total matches the amount', function (): void {
    $items = [new ItemData('Tricou', 'T1', 5000, 2), new ItemData('Livrare', 'L', 4170)];

    expect(CheckoutSessionPayload::lineItems(paymentRequest(items: $items)))->toBe([
        ['price_data' => ['currency' => 'ron', 'unit_amount' => 5000, 'product_data' => ['name' => 'Tricou']], 'quantity' => 2],
        ['price_data' => ['currency' => 'ron', 'unit_amount' => 4170, 'product_data' => ['name' => 'Livrare']], 'quantity' => 1],
    ]);
});

it('falls back to one line when the items do not add up', function (): void {
    expect(CheckoutSessionPayload::lineItems(paymentRequest(items: [new ItemData('Tricou', 'T1', 5000)])))->toHaveCount(1)
        ->and(CheckoutSessionPayload::withSessionId('https://x.ro/ok'))->toBe('https://x.ro/ok?session_id={CHECKOUT_SESSION_ID}');
});
