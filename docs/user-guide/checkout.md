# Paying on account

What a buyer sees at checkout, what a charge does, and how refunds change what the account owes.

## At checkout

The Net Terms gateway is listed with the store's other payment methods, under the name it has in **Commerce -> Settings -> Gateways**, when all of these are true:

- The order resolves to an account: the customer's own, or the one account the signed-in buyer buys on. A site module can point the order at a different account; see [company accounts](../dev-guide/company-accounts.md).
- The signed-in user is an active buyer on that account. In the control panel, the gateway is offered only when the signed-in staff user is a buyer on the account.
- The order isn't already complete. A completed order's balance is paid another way.
- The gateway's own conditions in **Commerce -> Settings -> Gateways** match the order.

Without an account or an active buyer, the gateway isn't listed, and the customer pays another way.

The gateway's payment form shows the buyer's available credit, unless the buyer's credit is unlimited. When the order is more than the buyer's available credit, or the account is suspended, the form says there isn't enough available credit and shows no pay button. If the checkout template doesn't pass the `order` to the payment form, the form uses the current cart. Net Terms doesn't split an order with another payment method.

## What a charge does

The gateway checks available credit again as it charges, and declines the order when the credit doesn't cover it. If two checkouts on the same account are paid at the same moment and there isn't room for both, the gateway declines one of them.

What happens to the order depends on the gateway's payment type:

- **Invoice billing:** the gateway purchases the order total, so Commerce marks the order paid. Net Terms adds the charge to the account's ledger, and the charge goes on the account's next invoice.
- **Order billing:** the gateway authorizes the order total. The order stays unpaid until you record payments against the order. See [billing by order](./order-billing.md).

## Changes to a paid order

In invoice billing, when a completed credit order's total changes, a queue job settles the change on the order and the ledger. The order stays paid, and the change goes on the account's next invoice.

- **The total rises.** The job adds a Net Terms purchase to the order and a charge to the ledger. It charges only what the order still owes, so a payment taken in the meantime covers the rest.
- **The total falls.** The job refunds only what the order is overpaid, and doesn't refund again what you refunded before the edit. It refunds the newest Net Terms purchase first, each up to what it can still refund, and adds an **Order changed** entry to the ledger for each refund.

A refund you give without editing the order takes the amount off what the buyer owes.

If the job runs twice for one edit, the second run changes neither the order nor the ledger.

### A raise over available credit

For an active buyer on an **Active** account, a raise has to fit the buyer's available credit. When it doesn't, clicking **Update order** in Commerce's order editor shows "There are errors on the order" with the reason, and doesn't save the order. Lower the order total, or make room on the account first: raise the credit limit or the buyer's sublimit.

On a suspended account, or for a deactivated buyer, Net Terms doesn't check available credit and adds the raise to what the buyer owes, even past the limit. The account pages then show how far over the limit the account or buyer is; see [over the limit](./accounts-and-buyers.md#over-the-limit).

The raise can still fail when the queue job runs, for example when another order uses the buyer's credit first, or a module saves the order without validation. The job then fails with the same reason. The saved order keeps a balance due, and the job changes neither the order's transactions nor the ledger. Make room on the account, then retry the job in **Utilities -> Queue Manager**.

## Refunds

In invoice billing, refund a Net Terms order from the order's page in the control panel, as with any other Commerce payment. Partial refunds work. The refund takes the amount off what the buyer owes.

If the order's charge is already on an invoice, the refund comes off that invoice line when the line's balance covers it. Otherwise Net Terms takes it off the buyer's next invoice.

In order billing, the charge is an authorization, which Commerce can't refund. Edit the order instead, and what the buyer owes follows its new total. To refund a recorded payment, see [changes after checkout](./order-billing.md#changes-after-checkout).

## Deleting an order

In either billing mode, an order on an invoice that isn't voided can't be deleted, because the customer already has that invoice. The orders index says why, with its delete button disabled. Commerce's order page doesn't show a message, and the order stays. A module that deletes the order in PHP gets `false` back. To delete the order, [void the invoice](./invoices.md#voiding-an-invoice) first.

A trashed order stops counting toward what the buyer owes, their available credit and the next invoice, and the account's **Ledger** tab doesn't list its entries. Restoring the order counts it again. A queue job settling an edit to the order's total still runs while the order is in the trash, so the ledger matches the order when you restore it. Deleting the order permanently deletes its ledger entries.

For order billing, see [changes after checkout](./order-billing.md#changes-after-checkout).
