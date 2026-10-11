# Configuration

Set these in **Net Terms -> Settings**, or in `config/net-terms.php`. Only admins see the settings page, and it is read-only where admin changes are turned off. The billing mode is the Net Terms gateway's payment type in Commerce, not a plugin setting. The settings page shows the current mode with a link to the gateway; see [billing by order](../user-guide/order-billing.md#choosing-order-billing). In order billing, the page leaves out the invoice settings: **Invoice number prefix**, **First invoice number**, **Storefront invoice path** and **Invoice PDF**.

| Setting | Label | Default | What it controls |
|---|---|---|---|
| `defaultSublimitMode` | Protect sublimits | `ceiling` | Whether each buyer's sublimit is protected for only them to spend, on an account that uses the default: `reserved` protects it, `ceiling` doesn't. The settings page doesn't accept `reserved` while an account on the default has sublimits adding up to more than its limit. Net Terms checks a value in `config/net-terms.php` only when someone saves the settings page. |
| `defaultPaymentTerms` | Default payment terms | `30` | Days from an invoice's issue date, or an order's date in order billing, to its due date, on an account that uses the default. |
| `invoiceNumberPrefix` | Invoice number prefix | `''` | Text before each invoice number, up to 20 characters. Blank uses `INV-`. Changing it doesn't renumber invoices already issued. |
| `invoiceNumberStart` | First invoice number | `1` | The number the first invoice uses, padded to at least six digits. The settings page refuses a change once an invoice exists, since a lower number would repeat one already issued. Net Terms doesn't check a value set in `config/net-terms.php`, so don't change it there once invoices exist. |
| `invoicePath` | Storefront invoice path | `null` | The site path of one invoice, with `{number}` for the invoice number. The `link` variable in invoice emails and reminders, when it is set. |
| `reminders` | Reminders | `[]` | Order billing only. The reminders, each with `days`, `type` (`dueSoon` before the due date, `overdue` after it), `enabled` and `emailUid`, the UID of the Commerce email it sends. A row without `emailUid` doesn't send. `days` is at least `1` for `dueSoon`. See [reminders](../user-guide/reminders.md). |
| `invoicePdfTemplate` | Template | `null` | Invoice billing only. A site template for the invoice PDF. Null uses the plugin's own. |
| `invoicePdfPaperSize` | Paper size | `letter` | Invoice billing only. `letter`, `legal` or `a4`. |
| `invoicePdfOrientation` | Orientation | `portrait` | Invoice billing only. `portrait` or `landscape`. |

```php
<?php

return [
	'defaultSublimitMode' => 'reserved',
	'defaultPaymentTerms' => 45,
	'invoiceNumberPrefix' => 'INV-',
	'invoiceNumberStart' => 1001,
	'invoicePath' => 'account/invoice?number={number}',
	'reminders' => [
		['days' => 5, 'enabled' => true, 'type' => 'dueSoon', 'emailUid' => 'the-due-soon-commerce-email-uid'],
		['days' => 1, 'enabled' => true, 'type' => 'overdue', 'emailUid' => 'the-overdue-commerce-email-uid'],
		['days' => 14, 'enabled' => true, 'type' => 'overdue', 'emailUid' => 'the-overdue-commerce-email-uid'],
	],
];
```

In invoice billing, the invoice email and reminders aren't settings: staff with the **Manage invoice emails** permission edit them in **Net Terms -> Emails**. See [invoice billing emails](../user-guide/reminders.md#invoice-billing-emails).
