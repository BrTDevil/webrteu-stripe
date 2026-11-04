<?php

declare(strict_types=1);

use WeBRTeu\Payments\Data\CustomerData;
use WeBRTeu\Payments\Data\ItemData;
use WeBRTeu\Payments\Data\PaymentRequestData;

const STRIPE = 'https://api.stripe.com/v1';
const SESSION_ID = 'cs_test_a1b2c3';
const INTENT_ID = 'pi_3Q1test';

function fixtureFile(string $name): string
{
    return (string) file_get_contents(__DIR__.'/Fixtures/'.$name);
}

/**
 * @return array<string, mixed>
 */
function fixtureJson(string $name): array
{
    return (array) json_decode(fixtureFile($name), true);
}

/**
 * @param  list<ItemData>  $items
 */
function paymentRequest(string $orderId = '123-1', int $amount = 14170, array $items = [], string $locale = 'ro'): PaymentRequestData
{
    return new PaymentRequestData(
        orderId: $orderId,
        amount: $amount,
        description: 'Comanda #123',
        customer: new CustomerData('ion@example.com', 'Ion', 'Popescu', '+40721000000'),
        returnUrl: 'https://shop.example/plata/stripe/return?order=123',
        items: $items,
        locale: $locale,
    );
}
