<?php

declare(strict_types=1);

use WeBRTeu\Payments\Exceptions\InvalidSignatureException;
use WeBRTeu\Stripe\Webhooks\SignatureVerifier;

const BODY = '{"id":"evt_1","type":"checkout.session.completed"}';
const TS = 1730700000;

it('signs {timestamp}.{raw body} with HMAC-SHA256', function (): void {
    expect(SignatureVerifier::sign(TS, BODY, 'whsec_test'))->toBe(hash_hmac('sha256', TS.'.'.BODY, 'whsec_test'))
        ->and(SignatureVerifier::header(TS, BODY, 'whsec_test'))->toBe('t='.TS.',v1='.hash_hmac('sha256', TS.'.'.BODY, 'whsec_test'));
});

it('accepts any v1 with any secret and ignores v0', function (): void {
    $header = 't='.TS.',v0=abc,v1=deadbeef,v1='.SignatureVerifier::sign(TS, BODY, 'whsec_new');

    SignatureVerifier::verify(BODY, $header, ['whsec_old', 'whsec_new'], 300, TS + 10);

    expect(true)->toBeTrue();
});

it('rejects invalid webhooks', function (string $body, ?string $header, int $now, string $message): void {
    expect(fn () => SignatureVerifier::verify($body, $header, ['whsec_new'], 300, $now))
        ->toThrow(InvalidSignatureException::class, $message);
})->with([
    'fără header' => [BODY, null, TS, 'Lipsește'],
    'timestamp nenumeric' => [BODY, 't=ieri,v1=x', TS, 'Lipsește'],
    'în afara toleranței' => [BODY, SignatureVerifier::header(TS, BODY, 'whsec_new'), TS + 301, 'toleranței'],
    'corp modificat' => [BODY.' ', SignatureVerifier::header(TS, BODY, 'whsec_new'), TS, 'nu este validă'],
    'alt secret' => [BODY, SignatureVerifier::header(TS, BODY, 'whsec_other'), TS, 'nu este validă'],
]);

it('uses the current time by default', function (): void {
    SignatureVerifier::verify(BODY, SignatureVerifier::header(time(), BODY, 'whsec_new'), ['whsec_new'], 300);

    expect(true)->toBeTrue();
});
