# Console commands

## `net-terms/invoices/issue`

Issues an invoice for every account with uninvoiced charges. The command includes suspended accounts, and skips accounts with a [draft](../user-guide/invoices.md#drafts). In order billing, the command does not issue invoices and exits with an error.

When an account is busy or an invoice can't be emailed, the command reports it, goes on to the next account, and exits with an error.

| Option | Description |
|---|---|
| `--account-id` | Issue for one account only. |
| `--send` | Email each invoice with the invoice email in **Net Terms -> Emails**. |

```sh
./craft net-terms/invoices/issue --send
```

To bill monthly, run it from cron on the first of the month:

```sh
0 6 1 * * /path/to/craft net-terms/invoices/issue --send
```

## `net-terms/reminders/send`

Sends the reminders whose moment falls on the day of the run and that haven't been sent. For what gets a reminder and a daily cron line, see [reminders](../user-guide/reminders.md).

The command prints `Reminders sent: N.` When any reminder fails, it also prints `Reminders that failed: N. See the log.` and exits with an error. Another run on the same day tries the failed reminders again, as [when a reminder is sent](../user-guide/reminders.md#when-a-reminder-is-sent) describes.

```sh
./craft net-terms/reminders/send
```
