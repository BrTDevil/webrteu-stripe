<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\ServiceProvider;
use WeBRTeu\Payments\Enums\PaymentStatus;
use WeBRTeu\Payments\Facades\Payments;
use WeBRTeu\Stripe\Exceptions\StripeException;
use WeBRTeu\Stripe\Facades\Stripe;
use WeBRTeu\Stripe\StripeServiceProvider;

it('reads a session status with the PaymentIntent expanded', function (string $fixture, PaymentStatus $expected, ?int $amount): void {
    Http::fake([STRIPE.'/checkout/sessions/*' => Http::response(fixtureFile($fixture))]);

    expect(Stripe::status(SESSION_ID))->status->toBe($expected)->orderId->toBe('123-1')->amount->toBe($amount);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && str_contains(urldecode($request->url()), 'expand[0]=payment_intent.latest_charge'));
})->with([
    ['session-paid.json', PaymentStatus::Paid, 14170],
    ['session-expired.json', PaymentStatus::Cancelled, 14170],
]);

it('reads a PaymentIntent status, including partial refunds', function (): void {
    Http::fake([STRIPE.'/payment_intents/*' => Http::sequence()->push(fixtureFile('intent-partially-refunded.json'))->push(fixtureFile('intent-requires-capture.json'))]);

    expect(Stripe::status(INTENT_ID))->status->toBe(PaymentStatus::PartiallyRefunded)->amount->toBe(5000)->orderId->toBe('123-1')
        ->and(Stripe::status(INTENT_ID))->status->toBe(PaymentStatus::Authorized)->amount->toBe(14170);
});

it('refunds an amount in bani, resolving the PaymentIntent from the session', function (): void {
    Http::fake([
        STRIPE.'/checkout/sessions/'.SESSION_ID => Http::response(['id' => SESSION_ID, 'payment_intent' => INTENT_ID]),
        STRIPE.'/refunds' => Http::sequence()->push(fixtureFile('refund.json'))->push(['status' => 'pending']),
    ]);

    expect(Stripe::refund(SESSION_ID, 5000))->status->toBe(PaymentStatus::Refunded)->providerRefundId->toBe('re_3Q1test')->amount->toBe(5000)
        ->and(Stripe::refund(INTENT_ID, 5000))->status->toBe(PaymentStatus::Pending)->providerRefundId->toBeNull();

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/refunds') && $request['payment_intent'] === INTENT_ID && $request['amount'] === 5000);
});

it('captures and cancels a PaymentIntent', function (): void {
    Http::fake([
        STRIPE.'/payment_intents/'.INTENT_ID.'/capture' => Http::response(['id' => INTENT_ID, 'status' => 'succeeded', 'metadata' => ['order_id' => '123-1']]),
        STRIPE.'/payment_intents/'.INTENT_ID.'/cancel' => Http::response(['id' => INTENT_ID, 'status' => 'canceled']),
    ]);

    expect(Stripe::capture(INTENT_ID, 14170))->status->toBe(PaymentStatus::Paid)->amount->toBe(14170)->orderId->toBe('123-1')
        ->and(Stripe::void(INTENT_ID))->status->toBe(PaymentStatus::Cancelled)->orderId->toBe('');

    Http::assertSent(fn (Request $request): bool => str_ends_with($request->url(), '/capture') && $request['amount_to_capture'] === 14170);
});

it('fails when the session has no PaymentIntent yet', function (): void {
    Http::fake([STRIPE.'/checkout/sessions/*' => Http::response(fixtureFile('session-created.json'))]);

    expect(fn () => Stripe::refund(SESSION_ID, 100))->toThrow(StripeException::class, 'nu are încă un PaymentIntent');
});

it('registers the gateway and the config tag', function (): void {
    expect(Payments::available())->toContain('stripe')
        ->and(Payments::gateway('stripe')->name())->toBe('stripe')
        ->and(ServiceProvider::pathsToPublish(StripeServiceProvider::class, 'stripe-config'))->toHaveCount(1);
});
