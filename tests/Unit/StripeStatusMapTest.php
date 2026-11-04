<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Log;
use WeBRTeu\Payments\Enums\PaymentStatus;
use WeBRTeu\Stripe\StripeStatusMap;

it('maps PaymentIntent statuses', function (string $status, PaymentStatus $expected): void {
    expect(StripeStatusMap::intent($status))->toBe($expected);
})->with([
    ['requires_payment_method', PaymentStatus::Pending], ['requires_confirmation', PaymentStatus::Pending],
    ['requires_action', PaymentStatus::Pending], ['processing', PaymentStatus::Pending],
    ['requires_capture', PaymentStatus::Authorized], ['succeeded', PaymentStatus::Paid], ['canceled', PaymentStatus::Cancelled],
]);

it('logs unknown PaymentIntent statuses', function (): void {
    Log::spy();

    expect(StripeStatusMap::intent('weird'))->toBe(PaymentStatus::Unknown);
    Log::shouldHaveReceived('warning')->once()->with('stripe.unknown_status', ['status' => 'weird']);
});

it('maps refund statuses', function (string $status, PaymentStatus $expected): void {
    expect(StripeStatusMap::refund($status))->toBe($expected);
})->with([
    ['pending', PaymentStatus::Pending], ['requires_action', PaymentStatus::Pending], ['succeeded', PaymentStatus::Refunded],
    ['failed', PaymentStatus::Failed], ['canceled', PaymentStatus::Cancelled], ['', PaymentStatus::Unknown],
]);

it('maps webhook events', function (string $type, array $object, PaymentStatus $expected): void {
    expect(StripeStatusMap::event($type, $object))->toBe($expected);
})->with([
    ['checkout.session.completed', ['payment_status' => 'paid'], PaymentStatus::Paid],
    ['checkout.session.completed', ['payment_status' => 'no_payment_required'], PaymentStatus::Paid],
    ['checkout.session.completed', ['payment_status' => 'unpaid'], PaymentStatus::Pending],
    ['checkout.session.async_payment_succeeded', [], PaymentStatus::Paid],
    ['checkout.session.async_payment_failed', [], PaymentStatus::Failed],
    ['checkout.session.expired', [], PaymentStatus::Cancelled],
    ['payment_intent.amount_capturable_updated', [], PaymentStatus::Authorized],
    ['payment_intent.succeeded', [], PaymentStatus::Paid],
    ['payment_intent.payment_failed', [], PaymentStatus::Failed],
    ['payment_intent.canceled', [], PaymentStatus::Cancelled],
    ['charge.refunded', ['refunded' => true], PaymentStatus::Refunded],
    ['charge.refunded', ['refunded' => false, 'amount_refunded' => 100], PaymentStatus::PartiallyRefunded],
    ['charge.refunded', ['amount_refunded' => 0], PaymentStatus::Unknown],
    ['customer.created', [], PaymentStatus::Unknown],
]);

it('maps sessions with and without an expanded PaymentIntent', function (array $session, PaymentStatus $expected): void {
    expect(StripeStatusMap::session($session))->toBe($expected);
})->with([
    'expirată' => [['status' => 'expired', 'payment_status' => 'unpaid'], PaymentStatus::Cancelled],
    'plătită' => [['status' => 'complete', 'payment_status' => 'paid'], PaymentStatus::Paid],
    'deschisă' => [['status' => 'open', 'payment_status' => 'unpaid'], PaymentStatus::Pending],
    'autorizată' => [['payment_intent' => ['status' => 'requires_capture', 'latest_charge' => 'ch_1']], PaymentStatus::Authorized],
    'returnată' => [['payment_intent' => ['status' => 'succeeded', 'latest_charge' => ['refunded' => true]]], PaymentStatus::Refunded],
    'fără status' => [['payment_intent' => []], PaymentStatus::Unknown],
]);
