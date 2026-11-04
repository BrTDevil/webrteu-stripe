<?php

declare(strict_types=1);

namespace WeBRTeu\Stripe\Requests;

use WeBRTeu\Payments\Data\ItemData;
use WeBRTeu\Payments\Data\PaymentRequestData;

/**
 * PaymentRequestData → corpul POST /v1/checkout/sessions (mode=payment). Sumele sunt în subunități (bani), int.
 */
final class CheckoutSessionPayload
{
    public const LOCALES = ['ro', 'en', 'bg', 'hu', 'de', 'fr', 'it', 'es'];

    /**
     * @param  list<string>|null  $paymentMethodTypes
     * @return array<string, mixed>
     */
    public static function make(
        PaymentRequestData $request,
        string $captureMode = 'automatic',
        ?string $cancelUrl = null,
        ?array $paymentMethodTypes = null,
    ): array {
        $metadata = ['order_id' => $request->orderId];

        return array_filter([
            'mode' => 'payment',
            'client_reference_id' => $request->orderId,
            'customer_email' => $request->customer->email,
            'locale' => in_array($request->locale, self::LOCALES, true) ? $request->locale : 'auto',
            'success_url' => self::withSessionId($request->returnUrl),
            'cancel_url' => $cancelUrl ?? $request->returnUrl,
            'line_items' => self::lineItems($request),
            'metadata' => $metadata,
            'payment_intent_data' => array_filter([
                'description' => $request->description,
                'metadata' => $metadata,
                'capture_method' => $captureMode === 'manual' ? 'manual' : null,
            ]),
            'payment_method_types' => $paymentMethodTypes,
        ], fn (mixed $value): bool => $value !== null);
    }

    /**
     * Produsele din comandă, dacă totalul lor e exact suma plății; altfel un singur rând cu descrierea.
     *
     * @return list<array<string, mixed>>
     */
    public static function lineItems(PaymentRequestData $request): array
    {
        $currency = strtolower($request->currency);
        $itemsTotal = array_sum(array_map(fn (ItemData $item): int => $item->total(), $request->items));

        if ($request->items === [] || $itemsTotal !== $request->amount) {
            return [self::line($currency, $request->description, $request->amount, 1)];
        }

        return array_map(fn (ItemData $item): array => self::line($currency, $item->name, $item->unitPrice, $item->quantity), $request->items);
    }

    /**
     * Stripe înlocuiește {CHECKOUT_SESSION_ID}, ca pagina de return să poată cere status().
     */
    public static function withSessionId(string $url): string
    {
        return $url.(str_contains($url, '?') ? '&' : '?').'session_id={CHECKOUT_SESSION_ID}';
    }

    /**
     * @return array<string, mixed>
     */
    private static function line(string $currency, string $name, int $unitAmount, int $quantity): array
    {
        return [
            'price_data' => ['currency' => $currency, 'unit_amount' => $unitAmount, 'product_data' => ['name' => $name]],
            'quantity' => $quantity,
        ];
    }
}
