# Upgrading

What to change on a site when updating Net Terms. Run migrations after every update:

```sh
./craft up
```

## Upgrading to 2.0.0

### Adding buyers from the storefront needs a handler

`net-terms/storefront/save-buyer` refuses every user unless an `Accounts::EVENT_DEFINE_BUYER_ELIGIBILITY` handler sets `isEligible` to `true`. In 1.x, it accepted any user. If your storefront templates let a company's admins add buyers, add a handler before updating; see [events](./reference/events.md#accountsevent_define_buyer_eligibility).

### Order-billing reminders need a Commerce email

In order billing, each reminder sends the Commerce email chosen in its row, and a row without one doesn't send. Reminders converted from 1.x have no email chosen, so they stop until you choose one:

1. In **Commerce -> Settings -> Emails**, add an email for each reminder message, such as "Payment due soon" and "Payment overdue".
2. In **Net Terms -> Settings**, choose the email in each reminder row's **Email** column.

See [order billing emails](./user-guide/reminders.md#order-billing-emails).

### Invoice-billing emails replace the system messages

In invoice billing, the invoice email and reminders are emails in **Net Terms -> Emails**, and the due-soon and overdue system messages are removed. The migration converts reminder days saved in the control panel to reminder emails, turned on, with the plugin's starter text. Copy any wording you changed in **Utilities -> System Messages**, and any site template set in **Invoice email template**, into those emails. See [invoice billing emails](./user-guide/reminders.md#invoice-billing-emails).

### Reminder settings removed

The `dueSoonReminderDays` and `overdueReminderDays` settings are removed, and Net Terms ignores them in `config/net-terms.php`. Move days set there:

- In order billing, to the `reminders` setting; see [configuration](./reference/configuration.md).
- In invoice billing, to reminders in **Net Terms -> Emails**.

### Other settings removed

**Invoice email template** (`invoiceEmailTemplate`) and **Storefront order path** (`orderPath`) are removed, and Net Terms ignores them in `config/net-terms.php`. For a branded invoice, set the invoice PDF's **Template** in **Net Terms -> Settings**; see [the invoice PDF](./user-guide/invoices.md#the-invoice-pdf).

### Invoice number prefix

New invoice numbers start with `INV-` in place of `CL-`. To keep `CL-`, set **Invoice number prefix** to `CL-` in **Net Terms -> Settings**. Invoices already issued keep their numbers.

### Settings page moved

The settings are in **Net Terms -> Settings**. **Settings -> Plugins -> Net Terms** opens that page.

### Twig API

`craft.netTerms.getOwed()` is now `getBalance()`, and `getOwedByBuyer()` is now `getBalanceByBuyer()`. Rename the calls in your templates.

### PHP API

- `Reminders::getSystemMessages()` and `ReminderType::messageKey()` are removed.
- `Reminders::sendReminders()` takes the start and end of a window, and sends only the reminders whose moment falls in it.
- `Accounts::getOwedByAccount()` is now `getBalanceByAccount()`, `Accounts::getOwedByBuyer()` is now `getBalanceByBuyer()`, and `Accounts::getOwedByBuyers()` is now `getBalanceByBuyers()`.
- `Ledger::getOwedByBuyers()` is now `getBalanceByBuyers()`, and `CreditOrders::getOwedByBuyerId()` is now `getBalanceByBuyerId()`.
- `Accounts::getAccountFigures()` returns the account's balance under `balance` in place of `owed`.
- The `$owedByBuyerId` parameter of `Accounts::getOverSublimitByBuyerId()` and `Accounts::getAvailableCreditByBuyerId()` is now `$balanceByBuyerId`. Rename it in calls that pass it by name.

### Translation keys

The `account.owed`, `buyer.owes` and `buyer.sublimitBelowOwed` keys are now `account.balance`, `buyer.balance` and `buyer.sublimitBelowBalance`, and `buyer.sublimitBelowBalance` takes `{balance}` in place of `{owed}`. Rename them in any `translations/*/net-terms.php` file on your site.

The `email.invoiceSubject`, `email.invoiceHeading`, `email.viewAndPay`, `reminder.orderLabel`, and due-soon and overdue `reminder.*` keys are removed. Move translated wording into the emails in **Net Terms -> Emails**, and for order-billing reminders into the Commerce email each one sends.

The `invoice.issueAndSend` and `invoice.issueConfirm` keys, `settings.remindersInstructions`, and the `settings.*` keys of the removed **Due-soon reminder**, **Overdue reminder**, **Invoice email template** and **Storefront order path** settings are also removed.
