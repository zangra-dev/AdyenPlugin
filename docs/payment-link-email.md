# Payment links: validity, optional email and overriding the email in your project

## 1. Behaviour from the order page (admin)

- The payment row of the order shows the current payment link in a read-only field with a **copy** button, the amount
  it was generated for and its expiry date.
- The form has a **Send email** checkbox, checked by default. When unchecked, the link is generated (or reused) and shown,
  but no email is sent. A request without the `send_email` parameter behaves as if it was checked.
- An existing link is **reused** (no call to Adyen, it is only sent again if *Send email* is checked) when all of these
  are true:
  - the payment is `processing`,
  - the link is not expired,
  - the amount requested equals the amount the link was generated for.
- Otherwise a new link is generated: the previous link is expired at Adyen and removed, then the new one is stored.

## 2. Validity of the link

Links are valid **15 days** by default. Adyen accepts a maximum of 70 days.

```yaml
# config/packages/sylius_adyen.yaml
sylius_adyen:
    payment_link:
        expiration_days: 15 # 1 to 70
```

Links created before this feature have no known expiry date: they are never reused, the first action on them generates a
new link.

Run the migrations after updating (`amount` and `expires_at` were added to `sylius_adyen_payment_link`).

## 3. Variables available in the email template

| Variable | Description |
|---|---|
| `orderNumber` | Order number |
| `customerFirstName` / `customerLastName` | Customer names (empty string when unknown) |
| `pspReference` | Latest Adyen PSP reference of the payment, **empty string when no payment attempt was made yet** |
| `paymentLinkId` | Adyen payment link id (`PL...`) |
| `paymentLinkUrl` | URL of the link |
| `expiresAt` | `\DateTimeInterface` or `null` |
| `order`, `customer`, `paymentLink` | The full objects, for anything else |

## 4. Overriding the email in your project

Keep the content of the email in your shop project (for example `zangra-sylius`), not in the plugin.

### Option A: override the plugin template (recommended)

Create `templates/bundles/SyliusAdyenPlugin/email/payment_link.html.twig`:

```twig
{% extends '@SyliusCore/Email/layout.html.twig' %}

{% block subject %}
    {{ 'app.email.payment_link.subject'|trans({'%order_number%': orderNumber}) }}
{% endblock %}

{% block body %}
    <p>Hello {{ customerFirstName }} {{ customerLastName }},</p>
    <p>
        Order {{ orderNumber }}: you can pay via
        <a href="{{ paymentLinkUrl }}">{{ paymentLinkUrl }}</a>
    </p>
    {% if pspReference %}<p>Reference: {{ pspReference }}</p>{% endif %}
    {% if expiresAt %}<p>Valid until {{ expiresAt|date('d/m/Y') }}</p>{% endif %}
{% endblock %}
```

### Option B: point the mailer to your own template

```yaml
# config/packages/sylius_mailer.yaml
sylius_mailer:
    emails:
        adyen_payment_link:
            subject: Payment link
            template: 'email/adyen_payment_link.html.twig'
```

When the variables are rendered through a translation that is marked `|raw`, escape them first (`|e`), as the default
template does.
