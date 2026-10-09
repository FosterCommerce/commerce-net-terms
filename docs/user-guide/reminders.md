# Reminders

How Net Terms emails customers about an invoice or order that is due soon or overdue.

## Turning reminders on

Go to **Settings -> Plugins -> Net Terms** and fill in either or both settings under **Reminders**:

| Setting | What it does |
|---|---|
| Due-soon reminder | Days before the due date to send the due-soon reminder. At least 1. Blank, the default, turns the reminder off. |
| Overdue reminder | Days after the due date to send the overdue reminder. `0` sends it as soon as the bill is overdue. Blank, the default, turns the reminder off. |

Then run the console command once a day from cron.

```sh
0 7 * * * /path/to/craft net-terms/reminders/send
```

For the command's output and exit codes, see [console commands](../reference/console-commands.md#net-termsreminderssend).

## What gets a reminder

| | Invoice billing | Order billing |
|---|---|---|
| Reminded about | Invoices that aren't voided and have a balance | Completed credit orders with a balance, not in the trash |
| Due date | The invoice's due date | The order date plus the order's [payment terms](./accounts-and-buyers.md#payment-terms) |
| Sent to | The account holder's email address | The order's email address |
| Linked page | **Storefront invoice path** | **Storefront order path** |

Net Terms sends only the reminders for the billing mode the Net Terms gateway uses. Suspended accounts get reminders too.

## When a reminder is sent

Each reminder has a moment: the due date less the due-soon days, or the due date plus the overdue days. A run sends the reminders whose moment came in the two days before it and that haven't been sent.

Net Terms skips a due-soon reminder whose days are more than the bill's payment terms (for example 30 days on 15-day terms).

Net Terms sends each reminder once per invoice or order. If a send fails, including when an edited system message has a Twig error, Net Terms logs the error. Later runs try the failed reminder again until its moment is more than two days old.

### When a due date changes

Each run works out the moments from the current due dates. A reminder not yet sent follows a due date that changed, after you [change an invoice's payment terms](./invoices.md#changing-an-invoices-payment-terms), change an account's terms in order billing, or edit an order's [payment terms field](./order-billing.md#payment-terms-per-order).

## Editing the emails

The reminders are Craft system messages. Edit them in **Utilities -> System Messages**:

- **When a Net Terms invoice or order is due soon**
- **When a Net Terms invoice or order is overdue**

Each message receives these variables:

| Variable | Contents |
|---|---|
| `recipientName` | The account holder's name for an invoice. The order customer's name for an order, or the order's email address. |
| `label` | `Invoice CL-000001` or `Order` and the order's reference. |
| `amountDue` | The balance, formatted. |
| `dateDue` | The due date, formatted. |
| `link` | The storefront page for the invoice or order, or `null` when its path setting is blank. |

To set the storefront paths, see [configuration](../reference/configuration.md).
