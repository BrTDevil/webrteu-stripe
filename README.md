# webrteu/stripe

[![PHP](https://img.shields.io/badge/PHP-%5E8.2-777bb4)](https://www.php.net/)
[![Laravel](https://img.shields.io/badge/Laravel-12%E2%80%9313-ff2d20)](https://laravel.com/)
[![Licență MIT](https://img.shields.io/badge/licen%C8%9B%C4%83-MIT-green)](LICENSE.md)
[![Packagist](https://img.shields.io/packagist/v/webrteu/stripe)](https://packagist.org/packages/webrteu/stripe)

Integrare Laravel pentru **Stripe Checkout** (pagina de plată găzduită de Stripe): plăți one-off cu card, Apple Pay,
Google Pay și celelalte metode active în Dashboard, webhook semnat HMAC-SHA256, captură, anulare și refund.
Implementează `Gateway`, `Refundable`, `Capturable` și `ConfirmsWebhooks` din `webrteu/payments`.

Nu este un pachet oficial Stripe.

> **Versiunea 2.x e o rescriere.** 1.x era legată de aplicația Auditor (modele `Order`, `AuditRequest`, emailuri,
> SmartBill). 2.x e generică, ca restul pachetelor `webrteu/*` de plăți. Aplicațiile pe 1.x rămân pe `^1.0`.

## Stripe direct (acest pachet) vs Laravel Cashier

| | `webrteu/stripe` | [Laravel Cashier](https://laravel.com/docs/billing) |
|---|---|---|
| Scop | plăți one-off pentru comenzi (magazin, servicii) | abonamente (SaaS): planuri, trial, upgrade, facturi Stripe |
| Model | rândul `payments` din `webrteu/payments`, legat polimorfic de comanda ta | trait `Billable` pe `User`, coloane `stripe_id`, tabele `subscriptions` |
| Interfață comună | aceeași cu Netopia, PayU, Revolut… (`Payments::start('stripe', …)`) | API propriu Stripe |
| Webhook | `/webhooks/payments/stripe`, idempotent, pe coadă | `/stripe/webhook` |
| Dependențe | doar `illuminate/*` (HTTP prin `Http::`) | `stripe/stripe-php`, `moneyphp/money` |

Pentru abonamente folosește Cashier. Cele două pot sta în aceeași aplicație: rutele nu se suprapun și ambele
citesc `STRIPE_KEY` / `STRIPE_SECRET`. Fiecare endpoint de webhook are însă secretul lui `whsec_`.

## Cerințe

- PHP `^8.2`, Laravel 12–13
- cont Stripe (modul test e suficient pentru dezvoltare)
- `webrteu/payments`

## Instalare

```bash
composer require webrteu/stripe
php artisan vendor:publish --tag=stripe-config
php artisan stripe:webhook:create --url=https://site.ro/webhooks/payments/stripe
```

Migrațiile sunt cele din `webrteu/payments` (`--tag=payments-migrations`).

## Configurare

```dotenv
STRIPE_KEY=pk_test_...              # Dashboard → Developers → API keys (folosită doar în frontend, opțional)
STRIPE_SECRET=sk_test_...           # cheia secretă; sk_live_... în producție
STRIPE_WEBHOOK_SECRET=whsec_...     # afișat de stripe:webhook:create; mai multe separate prin virgulă
STRIPE_API_VERSION=2025-03-31.basil # versiunea API fixată
STRIPE_CAPTURE_MODE=automatic       # automatic | manual (pre-autorizare, capturezi cu Stripe::capture)
STRIPE_CANCEL_URL=                  # unde revine clientul dacă renunță; gol = returnUrl
STRIPE_TIMEOUT=20
```

Nu există variabilă „sandbox”: modul test / live e dat de cheie (`sk_test_` / `sk_live_`).
Metodele de plată se aleg din Dashboard (`payment_method_types = null` în config); pentru doar card:
`'payment_method_types' => ['card']`. ⚠️ Câmpurile sesiunii se confirmă pe versiunea API fixată.

## Utilizare

```php
use WeBRTeu\Payments\Data\CustomerData;
use WeBRTeu\Payments\Data\PaymentRequestData;
use WeBRTeu\Payments\Facades\Payments;

$redirect = Payments::start('stripe', new PaymentRequestData(
    orderId: $order->number.'-'.$attempt,      // unic per încercare
    amount: 14170,                             // în bani
    description: 'Comanda #'.$order->number,
    customer: new CustomerData($email, $firstName, $lastName),
    returnUrl: route('plata.return', $order),  // Stripe adaugă ?session_id=cs_...
), payable: $order);

return $redirect->toResponse();               // redirect spre checkout.stripe.com
```

Dacă `items` sunt trimise și totalul lor e exact `amount`, apar ca rânduri separate în Checkout; altfel apare un singur
rând cu `description`. `orderId` ajunge în `client_reference_id` și în `metadata.order_id` (sesiune și PaymentIntent).
Crearea sesiunii trimite `Idempotency-Key: webrteu-checkout-{orderId}`.

| Metodă | Stripe |
|---|---|
| `status('cs_…')` / `status('pi_…')` | `GET /v1/checkout/sessions/{id}` / `GET /v1/payment_intents/{id}` (cu refund-urile) |
| `Stripe::capture($id, $bani)` | `POST /v1/payment_intents/{pi}/capture` |
| `Stripe::void($id)` | `POST /v1/payment_intents/{pi}/cancel` |
| `Stripe::refund($id, $bani)` | `POST /v1/refunds` |
| `Stripe::intentId('cs_…')` | id-ul PaymentIntent-ului sesiunii |

`provider_payment_id` salvat e id-ul sesiunii (`cs_…`); metodele de mai sus acceptă și `pi_…`.

## Webhook

```bash
php artisan stripe:webhook:create --url=https://site.ro/webhooks/payments/stripe
php artisan stripe:webhook:list
```

`create` afișează secretul `whsec_` o singură dată și nu scrie nimic în fișiere: îl pui tu în `STRIPE_WEBHOOK_SECRET`.
Local, cu Stripe CLI: `stripe listen --forward-to localhost:8000/webhooks/payments/stripe` (secretul afișat de CLI
merge pus lângă cel de producție, separat prin virgulă).

Ruta e înregistrată de `webrteu/payments` în afara grupului `web`. Dacă toate rutele tale sunt sub `web`:

```php
->withMiddleware(function (Middleware $middleware) {
    $middleware->validateCsrfTokens(except: ['webhooks/payments/*']);
})
```

Verificare: `Stripe-Signature` = `t={timestamp},v1={HMAC-SHA256("{timestamp}.{corp brut}", whsec)}`, comparat cu toate
`v1` și toate secretele; timestamp-ul trebuie să fie în toleranța de 300 s. Răspuns: HTTP 200 `{"received":true}`.
Evenimentul e semnat și conține obiectul complet, deci nu se mai cere API-ul, cu o excepție: la `charge.refunded`
comanda se ia din `metadata.order_id` al PaymentIntent-ului.

Aplicația ascultă evenimentul din core:

```php
Event::listen(PaymentStatusChanged::class, function (PaymentStatusChanged $event): void {
    if ($event->to === PaymentStatus::Paid) {
        $event->payment->payable?->markAsPaid();   // + factura, emailuri etc.
    }
});
```

Pagina de return nu marchează comanda plătită; poate afișa `Payments::gateway('stripe')->status($request->session_id)`.

## Statusuri

| Eveniment Stripe | `PaymentStatus` |
|---|---|
| `checkout.session.completed` cu `payment_status=paid` | `Paid` (altfel `Pending`, ex. transfer bancar) |
| `checkout.session.async_payment_succeeded` / `_failed` | `Paid` / `Failed` |
| `checkout.session.expired` | `Cancelled` |
| `payment_intent.amount_capturable_updated` | `Authorized` (la `capture_mode=manual`) |
| `payment_intent.succeeded` / `.canceled` | `Paid` / `Cancelled` |
| `payment_intent.payment_failed` | `Failed` (nu e în lista implicită: în Checkout clientul poate reîncerca) |
| `charge.refunded` | `Refunded` sau `PartiallyRefunded` |

## Comenzi Artisan

- `stripe:webhook:create {--url=} {--events=*}`
- `stripe:webhook:list`
- din core: `payments:reconcile`, de programat la 10 minute (prinde webhook-urile pierdute)

## Ce nu face pachetul

- abonamente, clienți Stripe salvați, Customer Portal (pentru asta: Laravel Cashier)
- Stripe Elements / Payment Element în pagina ta (doar Checkout găzduit)
- Stripe Connect
- nu scrie secretele webhook-urilor în `.env`

## Testare

```bash
composer test
STRIPE_SANDBOX_SECRET=sk_test_... vendor/bin/pest --group=sandbox
```

Fixture-urile sunt scrise după documentația Stripe. **De înlocuit cu răspunsuri reale** din modul test.

## Changelog

Vezi [CHANGELOG.md](CHANGELOG.md).

## Autor

[WeBRTeu](https://webrt.eu)

## Licență

MIT. Vezi [LICENSE.md](LICENSE.md).
