<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use WeBRTeu\Payments\Enums\RedirectType;
use WeBRTeu\Payments\Facades\Payments;
use WeBRTeu\Payments\Models\Payment;
use WeBRTeu\Stripe\Exceptions\StripeException;
use WeBRTeu\Stripe\StripeClient;

it('creates a Checkout Session and redirects to its URL', function (): void {
    Http::fake([STRIPE.'/checkout/sessions' => Http::response(fixtureFile('session-created.json'))]);

    $redirect = Payments::start('stripe', paymentRequest());

    expect($redirect->type)->toBe(RedirectType::Redirect)
        ->and($redirect->url)->toBe('https://checkout.stripe.com/c/pay/cs_test_a1b2c3')
        ->and(Payment::query()->sole()->provider_payment_id)->toBe(SESSION_ID);

    Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
        && $request->hasHeader('Authorization', 'Bearer sk_test_123')
        && $request->hasHeader('Stripe-Version', '2025-03-31.basil')
        && $request->hasHeader('Idempotency-Key', 'webrteu-checkout-123-1')
        && $request->isForm()
        && $request['mode'] === 'payment'
        && $request['client_reference_id'] === '123-1'
        && $request['line_items'][0]['price_data']['unit_amount'] === 14170
        && $request['metadata']['order_id'] === '123-1');
});

it('applies capture mode, cancel URL and payment methods from config', function (): void {
    Config::set('stripe.capture_mode', 'manual');
    Config::set('stripe.cancel_url', 'https://shop.example/cos');
    Config::set('stripe.payment_method_types', ['card']);
    Http::fake([STRIPE.'/checkout/sessions' => Http::response(fixtureFile('session-created.json'))]);

    Payments::gateway('stripe')->createPayment(paymentRequest());

    Http::assertSent(fn (Request $request): bool => $request['payment_intent_data']['capture_method'] === 'manual'
        && $request['cancel_url'] === 'https://shop.example/cos'
        && $request['payment_method_types'] === ['card']);
});

it('fails without a session URL and on API errors, without logging the key', function (): void {
    Log::spy();
    Http::fake([STRIPE.'/checkout/sessions' => Http::sequence()
        ->push(['id' => SESSION_ID])
        ->push(['error' => ['type' => 'invalid_request_error', 'message' => 'Invalid API Key provided: sk_test_***']], 401)
        ->push(['error' => ['code' => 'rate_limit']], 429)
        ->push('', 500)]);

    $gateway = Payments::gateway('stripe');

    expect(fn () => $gateway->createPayment(paymentRequest()))->toThrow(StripeException::class, 'URL-ul sesiunii')
        ->and(fn () => $gateway->createPayment(paymentRequest()))->toThrow(StripeException::class, 'Invalid API Key')
        ->and(fn () => $gateway->createPayment(paymentRequest()))->toThrow(StripeException::class, 'rate_limit')
        ->and(fn () => $gateway->createPayment(paymentRequest()))->toThrow(StripeException::class, 'eroare necunoscută');
    Log::shouldHaveReceived('warning')->withArgs(fn (string $message, array $context): bool => ! str_contains((string) json_encode($context), 'sk_test_123'));
});

it('redacts client_secret from logs', function (): void {
    expect(app(StripeClient::class)->redact(['client_secret' => 'pi_x_secret_y', 'id' => 'pi_x']))
        ->toBe(['client_secret' => '***', 'id' => 'pi_x']);
});
