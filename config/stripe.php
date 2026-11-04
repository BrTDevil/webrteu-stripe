<?php

declare(strict_types=1);

return [

    /*
    | Dashboard → Developers → API keys. sk_test_... pentru test, sk_live_... în producție.
    | Aceleași nume de variabile ca Laravel Cashier și ca webrteu/stripe 1.x.
    */
    'secret' => env('STRIPE_SECRET'),

    'key' => env('STRIPE_KEY'),

    /*
    | Versiunea API fixată, ca o schimbare la Stripe să nu strice pachetul neanunțat.
    */
    'api_version' => env('STRIPE_API_VERSION', '2025-03-31.basil'),

    /*
    | whsec_..., mai multe separate prin virgulă (rotație sau mai multe endpoint-uri).
    */
    'webhook_secrets' => array_values(array_filter(array_map(trim(...), explode(',', (string) env('STRIPE_WEBHOOK_SECRET'))))),

    /*
    | Toleranța pentru timestamp-ul din Stripe-Signature, în secunde (protecție la replay).
    */
    'webhook_tolerance' => 300,

    /*
    | automatic | manual (pre-autorizare + capturare).
    */
    'capture_mode' => env('STRIPE_CAPTURE_MODE', 'automatic'),

    /*
    | Unde se întoarce clientul dacă renunță. null = returnUrl din PaymentRequestData.
    */
    'cancel_url' => env('STRIPE_CANCEL_URL'),

    /*
    | null = metodele de plată active în Dashboard (recomandat). Altfel, ex. ['card'].
    */
    'payment_method_types' => null,

    'webhook_events' => [
        'checkout.session.completed',
        'checkout.session.async_payment_succeeded',
        'checkout.session.async_payment_failed',
        'checkout.session.expired',
        'payment_intent.amount_capturable_updated',
        'payment_intent.succeeded',
        'payment_intent.canceled',
        'charge.refunded',
    ],

    'timeout' => (int) env('STRIPE_TIMEOUT', 20),

];
