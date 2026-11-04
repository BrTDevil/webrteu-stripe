<?php

declare(strict_types=1);

namespace WeBRTeu\Stripe\Facades;

use Illuminate\Support\Facades\Facade;
use WeBRTeu\Stripe\StripeGateway;

/**
 * @method static \WeBRTeu\Payments\Data\PaymentStatusData status(string $providerPaymentId)
 * @method static \WeBRTeu\Payments\Data\RefundData refund(string $providerPaymentId, int $amount)
 * @method static \WeBRTeu\Payments\Data\PaymentStatusData capture(string $providerPaymentId, int $amount)
 * @method static \WeBRTeu\Payments\Data\PaymentStatusData void(string $providerPaymentId)
 * @method static string intentId(string $providerPaymentId)
 *
 * @see StripeGateway
 */
class Stripe extends Facade
{
    protected static function getFacadeAccessor(): string
    {
        return StripeGateway::class;
    }
}
