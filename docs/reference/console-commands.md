# Console commands

## `net-terms/invoices/issue`

Issues an invoice for every account with uninvoiced charges. The command includes suspended accounts. In order billing, the command does not issue invoices and exits with an error.

When an account is busy or an invoice can't be emailed, the command reports it, goes on to the next account, and exits with an error.

| Option | Description |
|---|---|
| `--account-id` | Issue for one account only. |
| `--send` | Email each invoice to its account holder. |

```sh
./craft net-terms/invoices/issue --send
```

To bill monthly, run it from cron on the first of the month:

```sh
0 6 1 * * /path/to/craft net-terms/invoices/issue --send
```

## `net-terms/reminders/send`

Sends the due-soon and overdue reminders whose moment came in the last two days and that haven't been sent. For what gets a reminder and a daily cron line, see [reminders](../user-guide/reminders.md).

The command prints `Reminders sent: N.` When any reminder fails, it also prints `Reminders that failed: N. See the log.` and exits with an error. Later runs try the failed reminders again, as [when a reminder is sent](../user-guide/reminders.md#when-a-reminder-is-sent) describes.

```sh
./craft net-terms/reminders/send
```
