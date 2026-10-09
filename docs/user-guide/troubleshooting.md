# Troubleshooting

Causes and fixes for problems staff run into with Net Terms.

## Net Terms isn't offered at checkout

Check each condition on [paying on account](./checkout.md#at-checkout), and these:

- The order's customer doesn't hold an account, and the signed-in buyer buys on more than one account in the store. A site module has to set which account the order charges; see [company accounts](../dev-guide/company-accounts.md).
- The buyer was removed from the account and is inactive. Click **Reactivate** on the account's **Buyers** tab.

## The payment form says there isn't enough available credit

The order is more than the buyer's available credit, or the account is suspended. Check the buyer's **Available** figure on the account's **Buyers** tab. Apply a payment, raise the credit limit or the buyer's sublimit, or set the account's status to **Active**.

## The account doesn't match an order

In invoice billing, a queue job settles a change to a completed credit order's total after Commerce saves the order. Check **Utilities -> Queue Manager** for a pending or failed "Updating the account for changed order" job with the order's reference.

A job fails without changing the order or the ledger when the order's new total charges the buyer more than their available credit. Raise the credit limit or the buyer's sublimit, then retry the job. See [a raise over available credit](./checkout.md#a-raise-over-available-credit).

## The payment type won't change

The Net Terms gateway's payment type changes only while every account is [settled](./order-billing.md#choosing-order-billing). Recording a payment adds unapplied credit, which also blocks the change. For each named account:

1. Apply its unapplied credit to what it owes, then record and apply payments for the rest.
2. Pay or void each unpaid invoice. An account can owe $0 and still have an unpaid invoice, for example when a refund on one buyer's order couldn't go on its paid invoice line. Void that invoice. If **Void invoice** is refused because payments are applied to it, reverse those payments on the invoice's page, void it, issue a new invoice, and apply the payments again.
3. Restore or permanently delete each of its credit orders in the trash.

## A second Net Terms gateway won't save

Every Net Terms gateway has to use the same payment type. Set the new gateway's **Credit Card Payment Type** to match the gateway the error names.

## A buyer's credit didn't reopen after a payment

Credit reopens when you apply the payment to the buyer's invoice line or order, not when you record it. Open the payment and check **Applied to**. An unapplied payment shows its amount under **Unapplied**.

## A sublimit won't save

A sublimit can't be lower than what the buyer owes. On an account with **Reserved** sublimits and a credit limit, the sublimits can't add up to more than the limit.

## A credit limit won't save

On an account with **Reserved** sublimits, the limit can't be lower than the active buyers' sublimits added together. Lower a sublimit first.

## An invoice won't void

Reverse the payments applied to it first.

## An order won't delete

In either billing mode, an order on an invoice that isn't voided can't be deleted. From the orders index, **Delete** opens a dialog saying "This order is on an issued invoice. Void the invoice before deleting.", with its delete button disabled. Commerce's order page doesn't show a message, and the order stays. Void the invoice first; see [deleting an order](./checkout.md#deleting-an-order).

## No invoice was issued

No buyer's uninvoiced entries add up to more than zero. That happens when there are no new charges, or when refunds and negative adjustments cancel them out.

## A reminder wasn't sent

Check each of these:

- The reminder's setting in **Settings -> Plugins -> Net Terms** isn't blank.
- `net-terms/reminders/send` runs every day; see [console commands](../reference/console-commands.md#net-termsreminderssend).
- The reminder's moment came no more than two days before a run.
- For a due-soon reminder, its days aren't more than the bill's payment terms.
- The invoice's account holder, or the order, has an email address. If not, the command logs the failure and exits with an error.
- The reminder wasn't already sent. Each reminder is sent once per invoice or order.
- The reminder's system message renders. When an edit breaks its Twig, Net Terms logs the error.

For what a reminder's moment is, see [reminders](./reminders.md#when-a-reminder-is-sent).
