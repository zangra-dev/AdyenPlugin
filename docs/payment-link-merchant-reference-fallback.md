# Payment links created in Adyen: matching on the merchant reference

Jira: ZN-1869. Reference orders: `398881`, `397812` (paid through a payment link created in the Adyen backoffice).

## 1. Problem

A payment link created directly in the Adyen backoffice is unknown to Sylius: there is no `AdyenReference` with its
psp reference and no stored `PaymentLink` with its link id. The `AUTHORISATION` webhook was therefore dropped
(`NoCommandResolvedException`) and the order stayed unpaid.

The only reliable information in the webhook is the `merchantReference`, which looks like `Order-000186791`.

## 2. Behaviour

`PaymentNotificationResolver` tries, in this order:

1. the psp reference (`AdyenReference`),
2. the payment link id of a link generated from Sylius (`PaymentLink`),
3. the Bancontact pending-payment fallback (orders still in `cart`, see `bancontact-pay-pending-payment-fallback.md`),
4. **the merchant reference fallback (this document)**.

The merchant reference fallback only applies when **all** of these are true:

- `eventCode` is `AUTHORISATION` and `success` is `true`,
- `additionalData.paymentLinkId` is present,
- the order is found from `merchantReference` (see 3),
- the order is **not** in `cart`,
- the notification currency equals the order currency.

Otherwise the notification is ignored as before.

## 3. Order lookup

`MerchantReferenceOrderResolver` accepts `Order-000186791`, `order-186791`, `000186791` and `186791`: the `Order-`
prefix is optional and case-insensitive, zero padding is ignored. The `number` of the order is tried with the raw
value, the digits only, and the digits left-padded with zeros to 9 characters.

## 4. Payment handling

`AuthorizeOrphanPaymentLinkHandler`:

- takes the **latest open** (`cart`/`new`/`processing`) payment of an Adyen payment method of the order,
- or, if there is none, creates a payment with the Adyen payment method of the notification,
- sets the payment amount to the amount **actually paid** (the amount of the webhook),
- then dispatches the existing `AuthorizePaymentByLink` command: payment details = notification data, `AdyenReference`
  with the new psp reference, payment authorised/completed.

One info line is written to the `monolog.logger.adyen` channel.

There is no grace period: nobody is expected to come back to the shop after paying a link.

## 5. Idempotency

A redelivered webhook carries the same psp reference. It is now matched by the `AdyenReference` created by the first
delivery, so no second payment is created.

## 6. Known limits

- Only `AUTHORISATION` is handled for these links. Refunds, cancellations and captures of such payments are not.
- A paid amount higher than the amount due is accepted as is (the money was received); the handler logs the amounts.
- The 9 digit padding assumption of the order numbers must be checked against the real numbers.

## 7. Tests

- `tests/Unit/Resolver/Order/MerchantReferenceOrderResolverTest.php`
- `tests/Unit/Resolver/Notification/PaymentNotificationResolverPaymentLinkFallbackTest.php`
- `tests/Unit/Bus/Handler/AuthorizeOrphanPaymentLinkHandlerTest.php`
