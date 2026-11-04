<?php

declare(strict_types=1);

namespace WeBRTeu\Stripe;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use WeBRTeu\Payments\Support\Redactor;
use WeBRTeu\Stripe\Exceptions\StripeException;

/**
 * HTTP brut spre Stripe API: Bearer {secret}, Stripe-Version fixat, corp form-encoded. Fără retry.
 */
class StripeClient
{
    public const BASE_URL = 'https://api.stripe.com/v1';

    /**
     * @param  array<string, mixed>  $params  corpul (POST) sau query string-ul (GET)
     * @return array<array-key, mixed>
     */
    public function send(string $method, string $path, array $params = [], ?string $idempotencyKey = null): array
    {
        $request = Http::withToken(Config::string('stripe.secret', ''))
            ->acceptJson()
            ->withHeaders(array_filter([
                'Stripe-Version' => Config::string('stripe.api_version', '2025-03-31.basil'),
                'Idempotency-Key' => $idempotencyKey,
            ]))
            ->timeout(Config::integer('stripe.timeout', 20));

        $url = self::BASE_URL.$path;
        $response = strtoupper($method) === 'GET'
            ? $request->get($url, $params)
            : $request->asForm()->send($method, $url, ['form_params' => $params]);

        $json = $response->json();
        $json = is_array($json) ? $json : [];

        if ($response->failed()) {
            Log::warning('stripe.request_failed', ['path' => $path, 'status' => $response->status(), 'response' => $this->redact($json)]);

            $error = is_array($json['error'] ?? null) ? $json['error'] : [];
            $message = $error['message'] ?? $error['code'] ?? null;

            throw new StripeException(sprintf('Stripe %s %s a eșuat (HTTP %d): %s', $method, $path, $response->status(), is_scalar($message) ? (string) $message : 'eroare necunoscută'));
        }

        return $json;
    }

    /**
     * @param  array<array-key, mixed>  $payload
     * @return array<array-key, mixed>
     */
    public function redact(array $payload): array
    {
        return Redactor::redact($payload, ['client_secret']);
    }
}
