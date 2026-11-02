# Changelog

## 1.0.0 — 2026-11-02

First public release.

- Fee per payment gateway: percentage, fixed amount, or both.
- Settings inside Configuration > Payment Gateways, saved with each gateway's "Save Changes".
- Gross-up option, maximum fee, minimum invoice amount, taxable fee, custom line text.
- Checkout summary with the calculated fee and the total to pay for the selected gateway.
- WHMCS 8 and 9 support (fee applied in `InvoiceCreation`; immutable invoices respected).
- PHP 7.2 to 8.5 support.
- English and Spanish translations.
- Admin simulator, invoice log and "Recalculate fee" button.
- Automatic migration from the pre-release `recargopagos` module.
