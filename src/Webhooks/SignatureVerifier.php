<?php

declare(strict_types=1);

namespace WeBRTeu\Stripe\Webhooks;

use WeBRTeu\Payments\Exceptions\InvalidSignatureException;

/**
 * Header-ul Stripe-Signature: `t={timestamp},v1={HMAC-SHA256("{timestamp}.{corp brut}", whsec)}`.
 * Poate conține mai multe `v1` (rotația secretului); `v0` se ignoră. Timestamp-ul e în secunde.
 */
final class SignatureVerifier
{
    public static function sign(int $timestamp, string $rawBody, string $secret): string
    {
        return hash_hmac('sha256', "{$timestamp}.{$rawBody}", $secret);
    }

    public static function header(int $timestamp, string $rawBody, string $secret): string
    {
        return "t={$timestamp},v1=".self::sign($timestamp, $rawBody, $secret);
    }

    /**
     * @param  list<string>  $secrets
     */
    public static function verify(string $rawBody, ?string $header, array $secrets, int $toleranceSeconds, ?int $now = null): void
    {
        $timestamp = null;
        $signatures = [];

        foreach (explode(',', (string) $header) as $part) {
            [$key, $value] = array_pad(explode('=', trim($part), 2), 2, '');

            if ($key === 't') {
                $timestamp = $value;
            } elseif ($key === 'v1') {
                $signatures[] = $value;
            }
        }

        if ($timestamp === null || ! ctype_digit($timestamp)) {
            throw new InvalidSignatureException('Lipsește timestamp-ul din Stripe-Signature.');
        }

        if (abs(($now ?? time()) - (int) $timestamp) > $toleranceSeconds) {
            throw new InvalidSignatureException('Webhook Stripe în afara toleranței de timp.');
        }

        foreach ($secrets as $secret) {
            $expected = self::sign((int) $timestamp, $rawBody, $secret);

            foreach ($signatures as $signature) {
                if (hash_equals($expected, $signature)) {
                    return;
                }
            }
        }

        throw new InvalidSignatureException('Semnătura Stripe-Signature nu este validă.');
    }
}
