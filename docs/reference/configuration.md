# Configuration

Set these in **Settings -> Plugins -> Net Terms**, or in `config/net-terms.php`. The billing mode is the Net Terms gateway's payment type in Commerce, not a plugin setting. The settings page shows the current mode with a link to the gateway; see [billing by order](../user-guide/order-billing.md#choosing-order-billing).

| Setting | Label | Default | What it controls |
|---|---|---|---|
| `defaultSublimitMode` | Default sublimit mode | `ceiling` | How sublimits behave on an account that uses the default: `ceiling` or `reserved`. The settings page doesn't accept `reserved` while an account on the default has sublimits adding up to more than its limit. Net Terms checks a value in `config/net-terms.php` only when someone saves the settings page. |
| `defaultPaymentTerms` | Default payment terms | `30` | Days from an invoice's issue date, or an order's date in order billing, to its due date, on an account that uses the default. |
| `invoicePath` | Storefront invoice path | `null` | The site path of one invoice, with `{number}` for the invoice number. Invoice emails and invoice reminders link here when it is set. |
| `invoiceEmailTemplate` | Invoice email template | `null` | A site template for the invoice email. Blank uses the plugin's own. |
| `orderPath` | Storefront order path | `null` | The site path of one credit order, with `{number}` for the order number. Order reminders link here when it is set. |
| `dueSoonReminderDays` | Due-soon reminder | `null` | Days before the due date to send the due-soon reminder. At least `1`. Null turns the reminder off. |
| `overdueReminderDays` | Overdue reminder | `null` | Days after the due date to send the overdue reminder. `0` sends it as soon as the bill is overdue. Null turns the reminder off. |

```php
<?php

return [
	'defaultSublimitMode' => 'reserved',
	'defaultPaymentTerms' => 45,
	'invoicePath' => 'account/invoice?number={number}',
	'invoiceEmailTemplate' => '_emails/net-terms-invoice',
	'orderPath' => 'account/net-terms-pay?number={number}',
	'dueSoonReminderDays' => 5,
	'overdueReminderDays' => 1,
];
```

The invoice email template receives `invoice`, `account`, and `invoiceUrl`. For the reminder emails and their variables, see [reminders](../user-guide/reminders.md).
