---
sessionId: session-261118-100258-u2g9
---

# Requirements

### Overview & Goals
Admins want to generate an Adyen payment link without always emailing it (they often contact the customer personally), to inject order data into the email, and to have clear, predictable behaviour for link reuse, regeneration and validity.

### Scope
**In scope**
- 'Send email' checkbox (default checked) on the order page payment-link form.
- Extra email variables: order number, customer first/last name, PSP reference (+ link id, URL, expiry).
- Display of the current link with a copy button, amount and expiry.
- Reuse-vs-regenerate rule; old link expired and deleted when a new one is generated.
- Configurable link validity, default 15 days.
- Docs on overriding the email template in the `zangra-sylius` project.

**Out of scope**
- Changing the actual template in `zangra-sylius` (done in that project).
- Shop API / customer-facing changes; webhooks / notification handling.

### Answers to the questions in the task
| Question | Today | After this change |
|---|---|---|
| Do we see the link / can we copy it? | No, the URL is never shown | Yes: read-only field + copy button on the payment row |
| Does 'Send payment link' generate and send (if checked)? | Always generates and always sends | Generates (or reuses) and sends only if 'Send email' is checked |
| Existing link: only resend it? | No, always regenerates | Yes when an active link exists for the same amount: no Adyen call, just resend (if checked) |
| Amount due changes: new link, old deleted? | Regeneration expires and deletes the old link | Kept: different amount (or expired link) => Adyen link expired, old row deleted, new one created |
| Validity | None sent, so Adyen default (24h) | `expiresAt` = now + 15 days (configurable, 1-70) |

### Functional Requirements
1. Form posts `send_email` (hidden `0` + checkbox `1`, checked by default). A missing parameter is treated as true (backward compatible).
2. Unchecked: link is generated/reused and shown, no email is sent, flash says the link was generated but not sent.
3. Email context contains: `orderNumber`, `customerFirstName`, `customerLastName`, `pspReference` (latest `AdyenReference` of the payment, empty string if none), `paymentLinkId` (Adyen `PL...`), `paymentLinkUrl`, `expiresAt`, plus the `order`, `customer` and `paymentLink` objects for full flexibility in overrides.
4. The default plugin template uses these variables; a shop overrides it via `templates/bundles/SyliusAdyenPlugin/email/payment_link.html.twig` or by pointing `sylius_mailer.emails.adyen_payment_link.template` to its own file.
5. Reuse rule: an existing link is reused when it is not expired and its stored amount equals the requested amount; otherwise a new one is generated.
6. `sylius_adyen.payment_link.expiration_days` (default 15, min 1, max 70, Adyen limit) feeds `expiresAt` in the Adyen payload.

### Non-Functional Requirements
- Backward compatible constructor/interface additions (optional params); DB migration for the new columns; translations en/fr/nl.

# Technical Design

### Current Implementation
- `Controller/Admin/GeneratePayLinkAction`: validates amount, sets payment amount, calls `PaymentLinkGenerator::generate()` then always `PaymentLinkEmailSender::send()`.
- `Generator/PaymentLinkGenerator`: `expireAndRemoveOldLinks()` always expires and removes existing links, calls `AdyenClient::generatePaymentLink()`, persists `PaymentLink` through `PaymentLinkFactory`.
- `Client/ClientPayloadFactory::createForPaymentLink()`: no `expiresAt` (Adyen default 24h).
- `Entity/PaymentLink` (+ `config/doctrine/PaymentLink.orm.xml`): only `paymentLinkId`, `paymentLinkUrl`, `createdAt`.
- `Email/Sender/PaymentLinkEmailSender`: sends email code `adyen_payment_link` with only `paymentLinkUrl`. Template `templates/email/payment_link.html.twig` renders a single translation `sylius_adyen.email.payment_link.content`.
- `templates/admin/order/.../actions/send_payment_link.html.twig` (hooked in `config/twig_hooks/admin/order/show.yaml`): amount input + submit only. Twig functions live in `Twig/Extension/PaymentLinkGenerationCheckerExtension`.
- PSP references are stored in `AdyenReference` (`AdyenReferenceRepository`, query by payment already exists); a fresh link only has its own `PL...` id.

### Key Decisions
- **PSP variable**: expose `paymentLinkId` always and `pspReference` from the latest `AdyenReference` when present (a PSP does not exist before a payment attempt).
- **Reuse unless amount changed**: reuse decision lives in a small service, not in the generator, so `PaymentLinkGenerator::generate()` keeps its 'always regenerate' contract.
- **Persist `amount` and `expiresAt` on `PaymentLink`** so reuse/expiry can be decided and displayed without calling Adyen.
- **Validity via config** `sylius_adyen.payment_link.expiration_days`, passed to `ClientPayloadFactory`.
- **Template override stays in the shop project**; the plugin only documents and keeps the template small and variable-rich.

### Architecture Diagram
``` mermaid
graph LR
  Form[Order page form] --> Action[GeneratePayLinkAction]
  Action --> Resolver[PaymentLinkReuseChecker]
  Resolver -->|reuse| Sender[PaymentLinkEmailSender]
  Resolver -->|regenerate| Gen[PaymentLinkGenerator]
  Gen --> Payload[ClientPayloadFactory expiresAt]
  Gen --> Link[(PaymentLink amount, expiresAt)]
  Action -->|send_email=1| Sender
  Sender --> Tpl[adyen_payment_link template]
  Refs[(AdyenReference)] --> Sender
```

### Proposed Changes
1. **Config**: `Configuration` adds `payment_link.expiration_days` (integer, default 15, min 1, max 70); `SyliusAdyenExtension` sets parameter `sylius_adyen.payment_link.expiration_days`; `config/services/adyen.xml` injects it into `ClientPayloadFactory`.
2. **Payload**: `createForPaymentLink()` adds `expiresAt` = `(new \DateTimeImmutable())->modify('+N days')->format(\DateTimeInterface::ATOM)` (Adyen requires ISO 8601).
3. **Entity/migration**: `PaymentLink` gets `amount` (int) and `expiresAt` (nullable datetime) with getters and `isExpired()`; update `PaymentLink.orm.xml`, `PaymentLinkInterface`, `PaymentLinkFactory(Interface)` (optional params); `PaymentLinkGenerator` stores `payment->getAmount()` and `expiresAt` from the Adyen response; new migration `Version2026...` under `src/Migrations` (existing rows: `amount` = payment amount, `expiresAt` = NULL treated as not known/expired for reuse so a fresh link is generated).
4. **Repository**: `PaymentLinkRepository::findLatestByPayment(PaymentInterface)`; `AdyenReferenceRepository` method returning the latest reference for a payment (existing payment query ordered by id desc).
5. **Reuse**: new `Checker`/`Resolver` service `PaymentLinkReuseChecker::findReusable(PaymentInterface, int $amount): ?PaymentLinkInterface` (latest link, not expired, amount equal, payment state processing); registered in `config/services/*.xml`.
6. **Controller**: reads `send_email` (default true); `$link = reuse->findReusable(...) ?? generator->generate(...)`; sends the email only if requested; flashes `generation_success`, `resent_success`, `generated_not_sent` (new keys in `translations/flashes.{en,fr,nl}.yaml`). Payment amount rollback in the catch is kept.
7. **Email**: `PaymentLinkEmailSender::send()` builds the extended context (needs `AdyenReferenceRepositoryInterface`); `templates/email/payment_link.html.twig` and `sylius_adyen.email.payment_link.content` use `%order_number%`, `%first_name%`, `%last_name%`, `%psp_reference%`, `%payment_link_url%`, `%expires_at%`. README/docs section 'Overriding the payment link email in your project'.
8. **Admin UI**: Twig function `sylius_adyen_payment_link(payment)` in the extension; `send_payment_link.html.twig` adds the 'Send email' checkbox (checked), and shows the current link (readonly input, copy button using `navigator.clipboard`, amount, expiry); button label becomes 'Resend' when a reusable link exists. New `sylius_adyen.ui.*` translation keys en/fr/nl.

### Risks
- Adyen rejects `expiresAt` > 70 days: capped by config validation.
- Old links (pre-migration) have no expiry/amount: treated as non-reusable, so first action regenerates them (safe).
- Reuse requires state `processing`; a `new` payment always generates.
- Copy button needs HTTPS/localhost for clipboard API; add a select-all fallback.

# Testing

### Validation Approach
Unit tests (PHPUnit, existing `tests/Unit` layout) plus the existing functional payment-link flow; run `vendor/bin/phpunit`, `phpstan`, `ecs`.

### Key Scenarios
- `GeneratePayLinkActionTest`: `send_email=0` => generator called, sender never called; default/`1` => sender called; reusable link => generator not called, sender called when checked.
- `PaymentLinkReuseCheckerTest`: same amount + active => reuse; different amount, expired, null expiry, no link => null.
- `ClientPayloadFactory` test: payload contains `expiresAt` ~ now + 15 days, and honours a custom value.
- `PaymentLinkGenerator` test: stores amount and expiry; old links still expired/removed.
- `PaymentLinkEmailSender` test: context contains order number, first/last name, link id, URL and PSP (and empty PSP when none).
- Twig extension test for `sylius_adyen_payment_link`.
- `Configuration` test: default 15, rejects 0 and 71.

### Edge Cases
- Customer without email with 'Send email' unchecked: link still generated (email assertion only when sending).
- Amount changed: old link expired at Adyen and row deleted; Adyen expiry failure does not block regeneration.
- Functional `PaymentLinkProcessTest` still passes with the new constructor arguments.

# Delivery Steps

### ✓ Step 1: Add configurable 15-day validity and persist link amount/expiry
Payment links are created with `expiresAt` (default 15 days) and the PaymentLink stores its amount and expiry.

- Add `payment_link.expiration_days` (default 15, min 1, max 70) to `Configuration` and set the parameter in `SyliusAdyenExtension`.
- Inject the parameter into `ClientPayloadFactory` (`config/services/adyen.xml`) and add `expiresAt` to `createForPaymentLink()`.
- Add `amount` and `expiresAt` to `PaymentLink`, `PaymentLinkInterface`, `PaymentLink.orm.xml`, `PaymentLinkFactory(Interface)` and populate them in `PaymentLinkGenerator`.
- Add a Doctrine migration in `src/Migrations`.
- Add/adjust unit tests for Configuration, payload factory, generator and factory.

### ✓ Step 2: Make email optional and reuse existing links unless the amount changed
The controller sends the email only when 'Send email' is checked and reuses an active same-amount link instead of regenerating.

- Add `PaymentLinkRepository::findLatestByPayment()` and the `PaymentLinkReuseChecker` service with DI registration.
- Update `GeneratePayLinkAction` to read `send_email` (default true), reuse-or-generate, and send conditionally.
- Add flash messages (success, resent, generated-not-sent) in `translations/flashes.{en,fr,nl}.yaml`.
- Update `GeneratePayLinkActionTest` and add `PaymentLinkReuseCheckerTest`.

### ✓ Step 3: Inject order data into the email and document the shop override
The payment link email exposes order number, customer names, PSP reference, link id, URL and expiry, and is documented as overridable from zangra-sylius.

- Extend `PaymentLinkEmailSender` context using the order, customer and the latest `AdyenReference` (new repository method).
- Update `templates/email/payment_link.html.twig` and the `sylius_adyen.email.payment_link.content` translations (en/fr/nl) to use the new variables.
- Document the override (`templates/bundles/SyliusAdyenPlugin/email/payment_link.html.twig` or `sylius_mailer.emails.adyen_payment_link.template`) with the variable list in README/docs.
- Add `PaymentLinkEmailSenderTest`.

### ✓ Step 4: Show the link with copy button and 'Send email' checkbox on the order page
Admins see the current link, expiry and amount, can copy the link, and choose whether to email it.

- Add the `sylius_adyen_payment_link(payment)` Twig function to `PaymentLinkGenerationCheckerExtension` (+ test).
- Update `send_payment_link.html.twig`: hidden `send_email=0` + checked checkbox `send_email=1`, readonly link field, copy button (clipboard API with select fallback), expiry and amount info, 'Resend' label when a reusable link exists.
- Add `sylius_adyen.ui.*` translation keys in `translations/messages.{en,fr,nl}.yaml`.
- Run phpunit, phpstan and ecs to verify the whole change.