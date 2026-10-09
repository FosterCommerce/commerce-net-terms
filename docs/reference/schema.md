# Schema

The tables are defined in `src/migrations/Install.php`. Every table has `dateCreated`, `dateUpdated`, and `uid`. Amounts are `decimal(14,4)` in the account's store currency.

## netterms_accounts

One account per customer per store.

| Column | Type | Notes |
|---|---|---|
| `id` | int | Primary key. |
| `storeId` | int | Foreign key to `commerce_stores`, cascade. |
| `holderId` | int | Foreign key to `users`, cascade. Unique with `storeId`. |
| `creditLimit` | decimal | Null for unlimited credit. |
| `sublimitMode` | string | `ceiling`, `reserved`, or null for the plugin default. |
| `paymentTerms` | int | Days. Null for the plugin default. |
| `status` | string | `active` or `suspended`. Default `active`. |

## netterms_buyers

Users who can charge to an account.

| Column | Type | Notes |
|---|---|---|
| `id` | int | Primary key. |
| `accountId` | int | Foreign key to `netterms_accounts`, cascade. Unique with `userId`. |
| `userId` | int | Foreign key to `users`, cascade. |
| `sublimit` | decimal | Null for no sublimit. |
| `active` | bool | False for a removed buyer with ledger entries or credit orders. Default true. |

## netterms_entries

The ledger, in invoice billing. The plugin does not change an entry's amount. Permanently deleting an order deletes its entries.

| Column | Type | Notes |
|---|---|---|
| `id` | int | Primary key. |
| `accountId` | int | Foreign key to `netterms_accounts`, cascade. |
| `buyerId` | int | Foreign key to `netterms_buyers`, set null. Null for an account adjustment. |
| `type` | string | `charge`, `refund`, `adjustment`, `payment`, `reversal`, or `orderChange`. |
| `amount` | decimal | Signed. Positive raises what is owed. |
| `orderId` | int | Foreign key to `commerce_orders`, cascade. The charged order, on charge, refund, and orderChange entries. |
| `transactionHash` | string | The Commerce transaction behind a charge, refund, or orderChange entry. Indexed. |
| `invoiceLineId` | int | Foreign key to `netterms_invoice_lines`, set null. Issuing an invoice sets it, and voiding the invoice clears it. |
| `applicationId` | int | Foreign key to `netterms_applications`, set null. For payment and reversal entries. |
| `note` | text | |
| `authorId` | int | Foreign key to `users`, set null. The staff member who added an adjustment, applied or reversed a payment, or edited the order behind a charge or orderChange entry. Null for a charge at checkout. |

## netterms_invoices

Issued invoices, in invoice billing.

| Column | Type | Notes |
|---|---|---|
| `id` | int | Primary key. |
| `accountId` | int | Foreign key to `netterms_accounts`, cascade. |
| `number` | string | Unique. `CL-` and a six-digit sequence. |
| `dateIssued` | datetime | |
| `dateDue` | datetime | |
| `dateVoided` | datetime | Null until voided. |

## netterms_invoice_lines

One buyer's part of an invoice. Its amount is the sum of its entries.

| Column | Type | Notes |
|---|---|---|
| `id` | int | Primary key. |
| `invoiceId` | int | Foreign key to `netterms_invoices`, cascade. |
| `buyerId` | int | Foreign key to `netterms_buyers`, set null. Null for account adjustments. |

## netterms_orders

Which account and buyer each order charged, in order billing. What the order owes is on the Commerce order.

| Column | Type | Notes |
|---|---|---|
| `id` | int | Primary key. |
| `orderId` | int | Foreign key to `commerce_orders`, cascade. Unique. |
| `accountId` | int | Foreign key to `netterms_accounts`, cascade. |
| `buyerId` | int | Foreign key to `netterms_buyers`, cascade. |

## netterms_reminders

One row per reminder sent, so each is sent once per invoice or order.

| Column | Type | Notes |
|---|---|---|
| `id` | int | Primary key. |
| `type` | string | `dueSoon` or `overdue`. Unique with `invoiceId`, and with `orderId`. |
| `invoiceId` | int | Foreign key to `netterms_invoices`, cascade. Null for an order reminder. |
| `orderId` | int | Foreign key to `commerce_orders`, cascade. Null for an invoice reminder. |

## netterms_payments

Money received.

| Column | Type | Notes |
|---|---|---|
| `id` | int | Primary key. |
| `accountId` | int | Foreign key to `netterms_accounts`, cascade. |
| `amount` | decimal | |
| `method` | string | `card`, `check`, `ach`, `wire`, or `other`. |
| `reference` | string | |
| `dateReceived` | datetime | |
| `note` | text | |
| `authorId` | int | Foreign key to `users`, set null. |

## netterms_applications

Part of a payment applied to an invoice line, or to an order in order billing.

| Column | Type | Notes |
|---|---|---|
| `id` | int | Primary key. |
| `paymentId` | int | Foreign key to `netterms_payments`, cascade. |
| `invoiceLineId` | int | Foreign key to `netterms_invoice_lines`, cascade. Invoice billing. |
| `orderId` | int | Foreign key to `commerce_orders`, cascade. Order billing. |
| `transactionId` | int | Foreign key to `commerce_transactions`, set null. The capture recording the application on the order. Order billing. |
| `amount` | decimal | The amount first applied. |
| `dateReversed` | datetime | Null while the application stands. Invoice billing; in order billing, a reversal is a refund of the capture. |
| `authorId` | int | Foreign key to `users`, set null. |
