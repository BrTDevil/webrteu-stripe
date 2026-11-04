<?php

declare(strict_types=1);

namespace WeBRTeu\Stripe;

use Illuminate\Support\Facades\Log;
use WeBRTeu\Payments\Enums\PaymentStatus;

/**
 * Statusurile Stripe (PaymentIntent, Checkout Session, Charge, Refund) → PaymentStatus.
 */
final class StripeStatusMap
{
    public const INTENTS = [
        'requires_payment_method' => PaymentStatus::Pending,
        'requires_confirmation' => PaymentStatus::Pending,
        'requires_action' => PaymentStatus::Pending,
        'processing' => PaymentStatus::Pending,
        'requires_capture' => PaymentStatus::Authorized,
        'succeeded' => PaymentStatus::Paid,
        'canceled' => PaymentStatus::Cancelled,
    ];

    public const REFUNDS = [
        'pending' => PaymentStatus::Pending,
        'requires_action' => PaymentStatus::Pending,
        'succeeded' => PaymentStatus::Refunded,
        'failed' => PaymentStatus::Failed,
        'canceled' => PaymentStatus::Cancelled,
    ];

    public const EVENTS = [
        'checkout.session.async_payment_succeeded' => PaymentStatus::Paid,
        'checkout.session.async_payment_failed' => PaymentStatus::Failed,
        'checkout.session.expired' => PaymentStatus::Cancelled,
        'payment_intent.amount_capturable_updated' => PaymentStatus::Authorized,
        'payment_intent.succeeded' => PaymentStatus::Paid,
        'payment_intent.payment_failed' => PaymentStatus::Failed,
        'payment_intent.canceled' => PaymentStatus::Cancelled,
    ];

    public static function intent(string $status): PaymentStatus
    {
        $mapped = self::INTENTS[$status] ?? null;

        if ($mapped === null) {
            Log::warning('stripe.unknown_status', ['status' => $status]);

            return PaymentStatus::Unknown;
        }

        return $mapped;
    }

    public static function refund(string $status): PaymentStatus
    {
        return self::REFUNDS[$status] ?? PaymentStatus::Unknown;
    }

    /**
     * Statusul unui eveniment de webhook, pe baza tipului și a obiectului din `data.object`.
     *
     * @param  array<array-key, mixed>  $object
     */
    public static function event(string $type, array $object): PaymentStatus
    {
        return match ($type) {
            'checkout.session.completed' => in_array($object['payment_status'] ?? null, ['paid', 'no_payment_required'], true) ? PaymentStatus::Paid : PaymentStatus::Pending,
            'charge.refunded' => self::charge($object) ?? PaymentStatus::Unknown,
            default => self::EVENTS[$type] ?? PaymentStatus::Unknown,
        };
    }

    /**
     * Statusul unei sesiuni Checkout, cu PaymentIntent-ul (și ultimul Charge) expandate, dacă există.
     *
     * @param  array<array-key, mixed>  $session
     */
    public static function session(array $session): PaymentStatus
    {
        $intent = is_array($session['payment_intent'] ?? null) ? $session['payment_intent'] : null;

        if ($intent !== null) {
            return self::paymentIntent($intent);
        }

        if (($session['status'] ?? null) === 'expired') {
            return PaymentStatus::Cancelled;
        }

        return in_array($session['payment_status'] ?? null, ['paid', 'no_payment_required'], true) ? PaymentStatus::Paid : PaymentStatus::Pending;
    }

    /**
     * @param  array<array-key, mixed>  $intent
     */
    public static function paymentIntent(array $intent): PaymentStatus
    {
        $charge = is_array($intent['latest_charge'] ?? null) ? self::charge($intent['latest_charge']) : null;

        return $charge ?? self::intent(is_string($intent['status'] ?? null) ? $intent['status'] : '');
    }

    /**
     * Refunded / PartiallyRefunded, sau null dacă nu s-a returnat nimic.
     *
     * @param  array<array-key, mixed>  $charge
     */
    public static function charge(array $charge): ?PaymentStatus
    {
        if (($charge['refunded'] ?? false) === true) {
            return PaymentStatus::Refunded;
        }

        return is_int($charge['amount_refunded'] ?? null) && $charge['amount_refunded'] > 0 ? PaymentStatus::PartiallyRefunded : null;
    }
}
