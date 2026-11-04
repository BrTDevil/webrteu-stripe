<?php

declare(strict_types=1);

use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Illuminate\Testing\TestResponse;
use WeBRTeu\Payments\Enums\PaymentStatus;
use WeBRTeu\Payments\Events\PaymentStatusChanged;
use WeBRTeu\Payments\Jobs\ProcessPaymentWebhook;
use WeBRTeu\Payments\Models\Payment;
use WeBRTeu\Payments\Models\PaymentEvent;
use WeBRTeu\Stripe\Webhooks\SignatureVerifier;

function postStripe(string $body, ?string $secret = 'whsec_new', ?int $timestamp = null): TestResponse
{
    $timestamp ??= time();

    return test()->call('POST', '/webhooks/payments/stripe', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_STRIPE_SIGNATURE' => $secret !== null ? SignatureVerifier::header($timestamp, $body, $secret) : 't='.$timestamp.',v1=x',
    ], $body);
}

beforeEach(function (): void {
    Payment::query()->create(['gateway' => 'stripe', 'order_id' => '123-1', 'provider_payment_id' => SESSION_ID, 'amount' => 14170, 'status' => PaymentStatus::Pending]);
});

it('queues the job for a valid signature and answers 200', function (): void {
    Queue::fake();

    postStripe(fixtureFile('event-checkout-completed.json'), 'whsec_old')->assertOk()->assertExactJson(['received' => true]);

    Queue::assertPushed(ProcessPaymentWebhook::class, fn (ProcessPaymentWebhook $job): bool => $job->webhook->eventId === 'evt_1Q1completed'
        && $job->webhook->orderId === '123-1'
        && $job->webhook->providerPaymentId === SESSION_ID
        && $job->webhook->amount === 14170);
});

it('marks the payment paid from checkout.session.completed, once, without calling the API', function (): void {
    Http::fake();
    Event::fake([PaymentStatusChanged::class]);

    postStripe(fixtureFile('event-checkout-completed.json'))->assertOk();
    postStripe(fixtureFile('event-checkout-completed.json'))->assertOk();

    expect(Payment::query()->sole())->status->toBe(PaymentStatus::Paid)->paid_at->not->toBeNull();
    Event::assertDispatchedTimes(PaymentStatusChanged::class, 1);
    Http::assertNothingSent();
});

it('does not mark paid when the amount differs', function (): void {
    $event = fixtureJson('event-checkout-completed.json');
    $event['data']['object']['amount_total'] = 100;

    postStripe((string) json_encode($event))->assertOk();

    expect(Payment::query()->sole()->status)->toBe(PaymentStatus::Pending);
});

it('finds the order through the PaymentIntent for charge.refunded', function (): void {
    Payment::query()->sole()->forceFill(['status' => PaymentStatus::Paid])->save();
    Http::fake([STRIPE.'/payment_intents/'.INTENT_ID => Http::response(['id' => INTENT_ID, 'metadata' => ['order_id' => '123-1']])]);

    postStripe(fixtureFile('event-charge-refunded.json'))->assertOk();

    expect(Payment::query()->sole())->status->toBe(PaymentStatus::Refunded)->refunded_amount->toBe(14170);
    Http::assertSent(fn (Request $request): bool => $request->method() === 'GET' && str_ends_with($request->url(), '/payment_intents/'.INTENT_ID));
});

it('records an error when the event cannot be tied to an order', function (array $object): void {
    Http::fake([STRIPE.'/payment_intents/*' => Http::response(['id' => INTENT_ID, 'metadata' => []])]);
    $body = (string) json_encode(['id' => 'evt_orphan', 'type' => 'charge.refunded', 'data' => ['object' => $object]]);

    postStripe($body)->assertServerError();

    expect(PaymentEvent::query()->where('event_id', 'evt_orphan')->sole())->error->toContain('evt_orphan')->processed_at->toBeNull()
        ->and(Payment::query()->sole()->status)->toBe(PaymentStatus::Pending);
})->with([
    'PaymentIntent fără metadata' => [['object' => 'charge', 'refunded' => true, 'payment_intent' => INTENT_ID]],
    'fără PaymentIntent' => [['object' => 'charge', 'refunded' => true]],
]);

it('rejects a wrong signature, an old timestamp and invalid events', function (string $body, ?string $secret, ?int $timestamp): void {
    postStripe($body, $secret, $timestamp)->assertStatus(400)->assertExactJson(['received' => false]);

    expect(Payment::query()->sole()->status)->toBe(PaymentStatus::Pending);
})->with([
    'semnătură greșită' => [fn (): string => fixtureFile('event-checkout-completed.json'), null, null],
    'timestamp vechi' => [fn (): string => fixtureFile('event-checkout-completed.json'), 'whsec_new', time() - 600],
    'nu e JSON' => ['x', 'whsec_new', null],
    'fără data.object' => ['{"id":"evt_1","type":"x"}', 'whsec_new', null],
]);
