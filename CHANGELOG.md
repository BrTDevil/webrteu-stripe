# Changelog

## [Unreleased] (2.0.0)

Rescriere completă peste `webrteu/payments`. **Incompatibil cu 1.x.**

### Adăugat
- `StripeGateway` (Stripe Checkout): sesiuni `mode=payment`, status, captură, anulare, refund; `Stripe-Version` fixat în config.
- Webhook verificat (`Stripe-Signature`, HMAC-SHA256, mai multe secrete, toleranță de timp), procesat prin coada din core.
- Comenzile `stripe:webhook:create` și `stripe:webhook:list`.
- Teste Pest (coverage 100%), PHPStan nivel 8, CI.

### Eliminat
- Dependențele de aplicația Auditor (`Order`, `AuditRequest`, `AuditLog`, emailuri, `webrteu/invoicing`, SmartBill).
- `stripe/stripe-php`, `filament/filament`, `dompdf/dompdf`, `laravel/framework` din `require`.
- Ruta `/stripe/webhook` (acum `/webhooks/payments/stripe`, din core).
- Sărirea verificării semnăturii în `local`: folosește `stripe listen` cu secretul lui.

## 1.0.1

Versiunea pentru Auditor (`checkout.session.completed`, facturare, emailuri).
