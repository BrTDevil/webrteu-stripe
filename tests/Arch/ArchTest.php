<?php

declare(strict_types=1);
use WeBRTeu\Payments\Exceptions\PaymentException;

arch('nu folosește funcții de debug')
    ->expect(['dd', 'dump', 'ray', 'var_dump', 'die'])
    ->not->toBeUsed();

arch('toate fișierele au strict types')
    ->expect('WeBRTeu\Stripe')
    ->toUseStrictTypes();

arch('fără Guzzle direct: HTTP doar prin Http::')
    ->expect('WeBRTeu\Stripe')
    ->not->toUse('GuzzleHttp');

arch('excepțiile extind PaymentException')
    ->expect('WeBRTeu\Stripe\Exceptions')
    ->toExtend(PaymentException::class);
