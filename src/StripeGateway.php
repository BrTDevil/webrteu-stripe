<?php

declare(strict_types=1);

namespace WeBRTeu\Stripe;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;
use WeBRTeu\Payments\Contracts\Capturable;
use WeBRTeu\Payments\Contracts\ConfirmsWebhooks;
use WeBRTeu\Payments\Contracts\Gateway;
use WeBRTeu\Payments\Contracts\Refundable;
use WeBRTeu\Payments\Data\PaymentRedirectData;
use WeBRTeu\Payments\Data\PaymentRequestData;
use WeBRTeu\Payments\Data\PaymentStatusData;
use WeBRTeu\Payments\Data\RefundData;
use WeBRTeu\Payments\Data\WebhookData;
use WeBRTeu\Payments\Enums\PaymentStatus;
use WeBRTeu\Payments\Enums\RedirectType;
use WeBRTeu\Payments\Exceptions\InvalidSignatureException;
use WeBRTeu\Stripe\Exceptions\StripeException;
use WeBRTeu\Stripe\Requests\CheckoutSessionPayload;
use WeBRTeu\Stripe\Webhooks\SignatureVerifier;

/**
 * Stripe Checkout (pagina găzduită de Stripe). providerPaymentId = id-ul sesiunii (cs_...);
 * refund / capture / void acceptă și id-ul PaymentIntent-ului (pi_...).
 *
 * Webhook-ul e semnat și conține obiectul complet, deci e sursa adevărului. confirm() cere API-ul doar
 * când evenimentul nu poartă order_id (ex. charge.refunded), ca să îl ia din metadata PaymentIntent-ului.
 */
class StripeGateway implements Capturable, ConfirmsWebhooks, Gateway, Refundable
{
    public function __construct(private readonly StripeClient $client) {}

    public function name(): string
    {
        return 'stripe';
    }

    public function createPayment(PaymentRequestData $request): PaymentRedirectData
    {
        /** @var list<string>|null $methods */
        $methods = Config::get('stripe.payment_method_types');
        $cancelUrl = Config::get('stripe.cancel_url');

        $json = $this->client->send(
            'POST',
            '/checkout/sessions',
            CheckoutSessionPayload::make($request, Config::string('stripe.capture_mode', 'automatic'), is_string($cancelUrl) && $cancelUrl !== '' ? $cancelUrl : null, $methods),
            'webrteu-checkout-'.$request->orderId,
        );

        $url = $json['url'] ?? null;

        if (! is_string($url) || $url === '') {
            throw new StripeException('Stripe nu a întors URL-ul sesiunii Checkout.');
        }

        return new PaymentRedirectData(RedirectType::Redirect, $url, [], $this->string($json, 'id'));
    }

    public function parseWebhook(Request $request): WebhookData
    {
        $raw = $request->getContent();

        /** @var list<string> $secrets */
        $secrets = array_values(array_filter(Config::array('stripe.webhook_secrets', []), is_string(...)));

        SignatureVerifier::verify($raw, $request->header('Stripe-Signature'), $secrets, Config::integer('stripe.webhook_tolerance', 300));

        $event = json_decode($raw, true);
        $object = is_array($event) && is_array($event['data'] ?? null) && is_array($event['data']['object'] ?? null) ? $event['data']['object'] : null;

        if (! is_array($event) || $object === null) {
            throw new InvalidSignatureException('Webhook-ul Stripe nu este un eveniment JSON valid.');
        }

        $type = $this->string($event, 'type');
        $status = StripeStatusMap::event($type, $object);
        $kind = $this->string($object, 'object');

        return new WebhookData(
            eventId: $this->string($event, 'id'),
            orderId: $this->orderId($object),
            status: $status,
            providerPaymentId: $this->nullable($kind === 'charge' ? $this->string($object, 'payment_intent') : $this->string($object, 'id')),
            amount: $this->amount($status, $object),
            raw: $event,
        );
    }

    public function webhookResponse(?WebhookData $webhook, bool $accepted): Response
    {
        return new JsonResponse(['received' => $accepted], $accepted ? 200 : 400);
    }

    public function confirm(WebhookData $webhook): WebhookData
    {
        if ($webhook->orderId !== '') {
            return $webhook;
        }

        $intentId = (string) $webhook->providerPaymentId;
        $orderId = str_starts_with($intentId, 'pi_') ? $this->orderId($this->client->send('GET', '/payment_intents/'.rawurlencode($intentId))) : '';

        if ($orderId === '') {
            throw new StripeException(sprintf('Evenimentul Stripe %s nu poate fi asociat unei comenzi (lipsește metadata.order_id).', $webhook->eventId));
        }

        return new WebhookData($webhook->eventId, $orderId, $webhook->status, $webhook->providerPaymentId, $webhook->amount, $webhook->raw);
    }

    /**
     * Acceptă id-ul sesiunii (cs_...) sau al PaymentIntent-ului (pi_...).
     */
    public function status(string $providerPaymentId): PaymentStatusData
    {
        if (str_starts_with($providerPaymentId, 'pi_')) {
            $intent = $this->client->send('GET', '/payment_intents/'.rawurlencode($providerPaymentId), ['expand' => ['latest_charge']]);
            $status = StripeStatusMap::paymentIntent($intent);

            return new PaymentStatusData($this->orderId($intent), $status, $providerPaymentId, $this->amount($status, $intent), $intent);
        }

        $session = $this->client->send('GET', '/checkout/sessions/'.rawurlencode($providerPaymentId), ['expand' => ['payment_intent.latest_charge']]);
        $status = StripeStatusMap::session($session);
        $intent = is_array($session['payment_intent'] ?? null) ? $session['payment_intent'] : [];

        return new PaymentStatusData($this->orderId($session), $status, $providerPaymentId, $this->amount($status, $intent + $session), $session);
    }

    public function refund(string $providerPaymentId, int $amount): RefundData
    {
        $json = $this->client->send('POST', '/refunds', ['payment_intent' => $this->intentId($providerPaymentId), 'amount' => $amount]);

        return new RefundData(
            amount: $amount,
            status: StripeStatusMap::refund($this->string($json, 'status')),
            providerRefundId: $this->nullable($this->string($json, 'id')),
            raw: $json,
        );
    }

    public function capture(string $providerPaymentId, int $amount): PaymentStatusData
    {
        $json = $this->client->send('POST', '/payment_intents/'.rawurlencode($this->intentId($providerPaymentId)).'/capture', ['amount_to_capture' => $amount]);

        return new PaymentStatusData($this->orderId($json), StripeStatusMap::intent($this->string($json, 'status')), $providerPaymentId, $amount, $json);
    }

    public function void(string $providerPaymentId): PaymentStatusData
    {
        $json = $this->client->send('POST', '/payment_intents/'.rawurlencode($this->intentId($providerPaymentId)).'/cancel');

        return new PaymentStatusData($this->orderId($json), PaymentStatus::Cancelled, $providerPaymentId, null, $json);
    }

    /**
     * cs_... → payment_intent-ul sesiunii (există doar după ce clientul a plătit / autorizat).
     */
    public function intentId(string $providerPaymentId): string
    {
        if (! str_starts_with($providerPaymentId, 'cs_')) {
            return $providerPaymentId;
        }

        $intent = $this->string($this->client->send('GET', '/checkout/sessions/'.rawurlencode($providerPaymentId)), 'payment_intent');

        if ($intent === '') {
            throw new StripeException(sprintf('Sesiunea Stripe %s nu are încă un PaymentIntent.', $providerPaymentId));
        }

        return $intent;
    }

    /**
     * @param  array<array-key, mixed>  $object
     */
    private function orderId(array $object): string
    {
        $metadata = is_array($object['metadata'] ?? null) ? $object['metadata'] : [];

        return $this->string($object, 'client_reference_id') !== '' ? $this->string($object, 'client_reference_id') : $this->string($metadata, 'order_id');
    }

    /**
     * Suma plătită (pentru verificarea din core) sau, la refund, suma returnată.
     *
     * @param  array<array-key, mixed>  $object
     */
    private function amount(PaymentStatus $status, array $object): ?int
    {
        $charge = is_array($object['latest_charge'] ?? null) ? $object['latest_charge'] : $object;
        $key = match (true) {
            in_array($status, [PaymentStatus::Refunded, PaymentStatus::PartiallyRefunded], true) => 'amount_refunded',
            isset($object['amount_total']) => 'amount_total',
            default => 'amount',
        };
        $source = $key === 'amount_refunded' ? $charge : $object;

        return is_int($source[$key] ?? null) ? $source[$key] : null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     */
    private function string(array $data, string $key): string
    {
        return is_scalar($data[$key] ?? null) ? (string) $data[$key] : '';
    }

    private function nullable(string $value): ?string
    {
        return $value !== '' ? $value : null;
    }
}
