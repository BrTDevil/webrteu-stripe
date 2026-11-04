<?php

declare(strict_types=1);

use WeBRTeu\Payments\Facades\Payments;

it('creates a real Checkout Session in Stripe test mode', function (): void {
    expect(Payments::gateway('stripe')->createPayment(paymentRequest('SMOKE-'.time()))->url)->toStartWith('https://checkout.stripe.com');
})->group('sandbox')->skip(! env('STRIPE_SANDBOX_SECRET'), 'Setează STRIPE_SANDBOX_SECRET (sk_test_...).');
