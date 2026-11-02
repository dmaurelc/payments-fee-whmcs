# Payments Fee WHMCS

**English** · [Español](README.es.md)

Free, open-source WHMCS addon that adds a **fee per payment gateway** to invoices: a percentage, a fixed amount, or both. Configure it right inside each gateway's settings, and show clients the fee and the total to pay at checkout, before they place the order.

- ✅ Compatible with **WHMCS 8.x and 9.x**, including WHMCS 9's immutable invoices
- ✅ PHP 7.2 – 8.5, no ionCube, no license keys
- ✅ English and Spanish (follows the admin's / client's language)
- ✅ MIT licensed

## Features

- **Per-gateway rules**: enable or disable the fee and choose *percentage*, *fixed amount* or *percentage + fixed amount* (e.g. 2.95% + $300).
- **Configured where you expect it**: a "Payment fee" section is added to every gateway under **Configuration > Payment Gateways** and saved with the gateway's own **Save Changes** button.
- **Gross-up (optional)**: calculates the fee so you receive the full amount after the gateway takes its commission. For example, 5% on $90,000 charges $4,737 instead of $4,500.
- **Maximum fee** and **minimum invoice amount** per gateway.
- **Taxable or not**: apply the invoice taxes to the fee, or don't.
- **Clear checkout**: next to each gateway the configured rule is shown (`+ 5%`), and under "Total Due Today" the calculated fee and the **total to pay** for the selected gateway. Both update when the client switches gateway.
- **Multi-currency**: fixed amounts are converted with the exchange rates configured in WHMCS. Zero-decimal currencies (CLP, JPY, PYG…) are rounded to whole units.
- **Invoice line in the client's language**, with a customizable text (`{gateway}`, `{detail}`).
- Skips *Add Funds* and *Mass Payment* invoices (configurable), plus a list of exempt clients.
- Admin tools: a **simulator**, a **log** of processed invoices, and a fee box with a **Recalculate fee** button on the admin invoice page.

## Why another gateway fee module?

Since **WHMCS 9.0, published invoices are immutable**: once an invoice leaves *Draft*, line items can no longer be added or removed. Most gateway fee modules add or change the fee line after the invoice is created, or when the client switches gateway, so they break or leave totals inconsistent on WHMCS 9. Many of them are also ionCube-encoded with old loaders that don't run on PHP 8.1+.

Payments Fee adds the fee in the `InvoiceCreation` hook. At that point WHMCS still allows line items to be changed, on both WHMCS 8 and 9.

## Installation

1. Download the latest release, or clone this repository.
2. Upload `modules/addons/paymentsfee/` to your WHMCS installation, keeping the same path: `/modules/addons/paymentsfee/`.
3. In the admin area go to **Configuration > System Settings > Addon Modules**, find **Payments Fee** and click **Activate**.
4. Review the general settings on the same screen. Optionally, under **Access Control**, tick the admin roles that may open the addon page (simulator and log).
5. Go to **Configuration > Payment Gateways**, expand a gateway and fill in the **Payment fee** section. Click **Save Changes**; "✓ Fee saved" appears next to the button.

> If you use another gateway fee module, deactivate it first so clients are not charged twice.

## Gateway settings

| Field | Description |
|---|---|
| Charge a fee | Turns the fee on or off for this gateway |
| Fee type | Percentage, Fixed amount, or Percentage + fixed amount |
| Percentage / Fixed amount | Comma or dot decimals are accepted (`3,5` = `3.5`) |
| Maximum fee | The fee never exceeds this amount (empty = no cap) |
| Minimum invoice amount | No fee below this amount (empty = always) |
| Gross-up | Charges `(amount + fixed) / (1 − %) − amount`, so you receive the full amount after the gateway commission |
| Tax | Applies the invoice taxes to the fee line |
| Invoice line text | Optional per-gateway text. Supports `{gateway}` and `{detail}` |

Fixed amounts, maximum and minimum are entered in the **default currency**.

## General settings

*Configuration > System Settings > Addon Modules > Payments Fee*

| Setting | Default | Description |
|---|---|---|
| Invoice line text | *(empty)* | Empty uses `Payment fee ({gateway})` / `Recargo por pago con {gateway}` in the client's language |
| Show calculation detail | On | Appends the rule to the line, e.g. `(3.5%)` |
| Percentage base | Total | Total incl. taxes, subtotal, or balance due (total − applied credit) |
| Skip "Add Funds" invoices | On | |
| Skip mass payment invoices | On | The grouped invoices already carry their own fee |
| Exempt clients | — | Comma-separated client IDs |
| Gateway change on published invoices | Automatic | *Automatic*: recalculate on WHMCS 8, keep on WHMCS 9. *Always recalculate*: only if you enabled `$allow_adminarea_invoice_mutation`. *Keep*: never modify published invoices |
| Show fee in checkout | On | Checkout summary and gateway labels |

## When is the fee applied?

| Situation | WHMCS 8 | WHMCS 9 |
|---|---|---|
| Cart order (gateway chosen at checkout) | ✅ | ✅ |
| Automatic renewal invoice (cron) | ✅ | ✅ |
| Invoice created via API | ✅ | ✅ |
| Admin-created draft (recalculated as items change) | ✅ | ✅ |
| Client changes gateway on an unpaid invoice | ✅ recalculated | Original fee kept, client sees a notice |
| Paid, cancelled, refunded or partially paid invoice | Never modified | Never modified |

**Tip for WHMCS 9:** since the fee is fixed by the gateway the invoice was created with, make sure each client has the right default gateway. The checkout shows the fee of every gateway before the client pays.

## Languages

The admin UI follows the admin's language and the checkout follows the client's language. Invoice lines generated by cron or the API use the client's language, or the system default. Included languages: **English** and **Spanish**. To add a language, copy `lang/english.php` to `lang/<language>.php` (e.g. `portuguese-br.php`), translate the values and add the language mapping in `lib/Lang.php`. Pull requests are welcome.

## Uninstalling

Deactivating keeps the tables. To remove everything:

```sql
DROP TABLE mod_paymentsfee_rules;
DROP TABLE mod_paymentsfee_invoices;
```

Fee lines already on invoices are not removed.

## Development

The tests run without WHMCS; they use a small fake of the WHMCS database on SQLite:

```bash
php tests/tests.php
```

Project layout:

```
modules/addons/paymentsfee/
├── paymentsfee.php      # addon config, activation, admin page
├── hooks.php            # WHMCS hooks
├── lang/                # english.php, spanish.php
└── lib/
    ├── Calculator.php   # pure fee math
    ├── FeeService.php   # applies/removes the fee on invoices
    ├── GatewayPage.php  # fields under Configuration > Payment Gateways
    ├── Frontend.php     # checkout summary and labels
    ├── Admin.php        # addon page and invoice box
    ├── Repository.php   # database access
    ├── Env.php          # WHMCS version / immutability detection
    └── Lang.php         # translations
```

## Legal notice

Surcharging customers for a payment method may be restricted by local law or by your agreements with payment providers. Check what applies to you before enabling it.

## License

[MIT](LICENSE) © [dmaurel.cl](https://dmaurel.cl)
