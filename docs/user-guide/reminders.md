# Reminders and emails

How Net Terms emails customers when an invoice is issued, and about an invoice or order that is due soon or overdue.

## Turning reminders on

Each reminder is a number of days before or after the due date. A reminder before the due date needs at least 1 day. A reminder 0 days after the due date sends on the day the bill becomes overdue. Add as many as you need, such as 7 and 1 days before the due date, and 1, 14 and 30 days after it.

**In invoice billing**, go to **Net Terms -> Emails**. A new install starts with two reminders, **Due soon** (7 days before) and **Overdue** (1 day after), turned off. Edit them, or click **New reminder**, and set **Schedule** and **Enabled**. See [invoice billing emails](#invoice-billing-emails). Two reminders with the same schedule both send.

**In order billing**, go to **Net Terms -> Settings**. Under **Reminders**, click **Add a reminder** for each one, and set its columns:

| Column | What it does |
|---|---|
| Days | How many days before or after the due date to send it. |
| When | **Before the due date** or **After the due date**. |
| Email | The Commerce email the reminder sends; see [order billing emails](#order-billing-emails). |
| Enabled | Turn a reminder off without deleting it. |

Each row needs its own days and timing.

Then run the console command once a day from cron.

```sh
0 7 * * * /path/to/craft net-terms/reminders/send
```

For the command's output and exit codes, see [console commands](../reference/console-commands.md#net-termsreminderssend).

## What gets a reminder

| | Invoice billing | Order billing |
|---|---|---|
| Reminded about | Invoices that aren't voided and have a balance | Completed orders charged to an account with a balance, not in the trash |
| Due date | The invoice's due date | The order date plus the order's [payment terms](./accounts-and-buyers.md#payment-terms) |
| The email | The reminder in **Net Terms -> Emails** | The Commerce email the reminder row chooses |
| Sent to | The reminder's **Recipient**, **CC’d Recipient** and **BCC’d Recipient** | The Commerce email's recipient |
| Linked page | **Storefront invoice path** | The page the Commerce email's template links to |

Net Terms sends only the reminders for the billing mode the Net Terms gateway uses. Suspended accounts get reminders too.

## When a reminder is sent

Each reminder has a moment: the due date less its days, or the due date plus its days. A run sends the reminders whose moment falls on the day of the run and that haven't been sent. A reminder whose day passed without a run isn't sent later, and neither is one whose moment had already passed when you added it.

Net Terms skips a reminder before the due date whose days are more than the bill's payment terms (for example 30 days on 15-day terms).

Net Terms sends each reminder once per invoice or order and schedule, so changing a reminder's days sends it again on the new day. If a send fails, including when an edited email, or an order-billing reminder's Commerce email template, has a Twig error, Net Terms logs the error. Another run on the same day tries the failed reminder again.

### When a due date changes

Each run works out the moments from the current due dates. A reminder not yet sent follows a due date that changed, after you [change an invoice's payment terms](./invoices.md#changing-an-invoices-payment-terms), change an account's terms in order billing, or edit an order's [payment terms field](./order-billing.md#payment-terms-per-order).

## Editing the emails

### Invoice billing emails

In invoice billing, edit the invoice email and reminders in **Net Terms -> Emails**. Net Terms stores them in the database, so you can edit them where admin changes are off. Editing them needs the **Manage invoice emails** permission.

Each email has these fields:

| Field | What it does |
|---|---|
| Name | What the email is called in the control panel. |
| Schedule | Reminders only. The days before or after the due date. The invoice email sends from **Save and send**, **Email invoice** and `net-terms/invoices/issue --send`. |
| Enabled | Turn the email off without deleting it. |
| From Email Address, From Name | Leave blank to use Craft's email settings. These can begin with an environment variable. |
| Email Subject | Accepts Twig, such as `{{ label }} is due on {{ dateDue }}`. |
| Reply To | Leave blank to use the **Reply-To Address** in Craft's email settings, or the sender when that is blank. Accepts Twig. |
| Recipient | **Account holder**, **Buyers on the invoice**, or both. |
| BCC’d Recipient, CC’d Recipient | More addresses, separated by commas. Each can begin with an environment variable, and the field accepts Twig. |
| Email Body | Markdown. Accepts Twig. On Craft Pro, Net Terms wraps it in the HTML email template set in **Settings -> Email**. If that template fails to render, Net Terms sends the text body alone and logs a warning. |
| Attach the invoice PDF | Attach the [invoice PDF](./invoices.md#the-invoice-pdf). When the PDF fails to render, Net Terms sends the email without it and logs the error. |

The subject, body, reply-to, CC and BCC fields receive these variables:

| Variable | Contents |
|---|---|
| `recipientName` | The account holder's name. |
| `label` | `Invoice` and the invoice number, such as `Invoice INV-001001`. |
| `amountDue` | The balance, formatted. |
| `dateDue` | The due date, formatted. |
| `link` | The page set in **Storefront invoice path**, or `null` when it is blank. |
| `lines` | The invoice's lines, each with `buyer` (the buyer's name, or `Account`) and `amount` (formatted). |
| `notes` | The invoice's [notes](./invoices.md#creating-an-invoice), or `null` when the invoice has no notes. |
| `days` | The reminder's days before or after the due date, such as `7`. `null` for the invoice email. |

```twig
{% for line in lines %}
- {{ line.buyer }}: {{ line.amount }}
{% endfor %}
```

### Order billing emails

In order billing, each reminder row sends a Commerce email, the same kind as an order confirmation. Commerce keeps these emails in project config, and each body is a Twig template in your site. The reminder rows are plugin settings; see [configuration](../reference/configuration.md).

1. In **Commerce -> Settings -> Emails**, add an email for each reminder message, such as "Payment due soon" and "Payment overdue". Set its recipient, template and, to attach the order PDF, its **PDF Attachment**.
2. Leave the email off every order status, so Commerce doesn't also send it when an order's status changes.
3. On the Net Terms settings page, choose the email in each reminder row's **Email** column. The list shows the enabled emails of the current store, and Net Terms doesn't save an enabled row without one. A row with no email, such as one converted from an earlier version or from invoice billing, doesn't send.

The template receives Commerce's usual `order`, and `orderData.netTerms` with the reminder's details:

| Variable | Contents |
|---|---|
| `orderData.netTerms.type` | `dueSoon` or `overdue`. |
| `orderData.netTerms.days` | The reminder's days before or after the due date. |
| `orderData.netTerms.dateDue` | The due date, a `DateTime`. |
| `orderData.netTerms.amountDue` | What the order still owes, formatted. |

```twig
{% set reminder = orderData.netTerms %}
<p>Order {{ order.reference }} is due on {{ reminder.dateDue|date('long') }}. The amount due is {{ reminder.amountDue }}.</p>
```
