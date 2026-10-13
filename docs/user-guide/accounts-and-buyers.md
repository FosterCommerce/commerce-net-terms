# Accounts and buyers

How an account is set up, who can charge to it, and how much each buyer can spend.

## Accounts

An account is one customer's terms and credit limit in one store. The control panel opens new accounts in the primary store. Creating, editing and deleting accounts, buyers and adjustments needs the **Manage accounts, buyers and adjustments** permission. Go to **Net Terms -> Accounts** to see every account with its limit, its balance, the credit it has left and any payments not yet applied.

An account's page has tabs for **Account**, with the settings below, then **Buyers**, **Invoices** (**Orders** in [order billing](./order-billing.md)), **Payments**, and **Ledger** in invoice billing only. Invoices, orders and payments show 50 to a page, and the ledger 100, newest first.

| Setting | What it does |
|---|---|
| Account holder | The customer the account belongs to, who receives its invoices. Set once, when the account is created. Net Terms adds the holder as the account's first buyer, with no sublimit. |
| Unlimited credit | Removes the credit limit. Buyers' sublimits still apply, and **Available** shows **Unlimited** for a buyer without one. |
| Credit limit | The highest balance the account can have. |
| Protect sublimits | Whether each buyer's sublimit is protected for only them to spend. Defaults to the plugin setting. |
| Payment terms | Days from an invoice's issue date, or an order's date in order billing, to its due date. Blank uses the plugin's **Default payment terms**, 30 days. See [payment terms](#payment-terms). |
| Status | **Active**, or **Suspended** to stop buyers checking out on the account. Suspending an account doesn't change its balance. |

### Over the limit

An account or buyer can have a balance over its limit. Net Terms checks available credit only at checkout, and on an order edit in invoice billing when the buyer and account are active. These can each take an account over its limit:

- lowering the credit limit
- a positive adjustment
- restoring a trashed order
- reversing a payment
- an order edit in order billing
- a raise on a suspended account or for a deactivated buyer

**Available** then shows $0. The account page and the accounts list show "Over the credit limit by $2.00" in red, and the **Buyers** tab shows "Over the sublimit by $4.00" beside the buyer. Applying payments to their invoice lines or orders brings them back under the limit.

A buyer who is over their sublimit can still be saved or reactivated. Only a new or lowered sublimit can't be below their balance.

### Payment terms

| | Invoice billing | Order billing |
|---|---|---|
| Due date | The issue date plus the account's terms, set when the invoice is issued | The order date plus the order's **Payment terms (Net Terms)** field, or the account's terms when the field is blank |
| Changing one bill's terms | [Change the invoice's payment terms](./invoices.md#changing-an-invoices-payment-terms) | Set the order's [payment terms field](./order-billing.md#payment-terms-per-order) |
| After the account's terms change | Invoices already issued keep their due dates | Orders without their own terms are due on the new terms |

For why invoice billing has no per-order terms, see [payment terms per order](./order-billing.md#payment-terms-per-order). For how a changed due date affects reminders, see [reminders](./reminders.md#when-a-due-date-changes).

### Deleting an account

Only an account with no ledger entries, orders, invoices or payments can be deleted. To delete one, open it and choose **Delete account** from the menu beside **Save**. To stop an account that has history, set its status to **Suspended**.

A user who holds an account, or who has ledger entries or orders charged to an account as a buyer, can't be deleted. **Delete** opens a dialog that says why. To delete an account holder, delete their account first, which works only for an account with no history.

## Buyers

A buyer is a user who can charge orders to the account. The gateway is available only to buyers. A new account starts with its holder as a buyer. If the holder shouldn't buy, for example when the holder is a company's shared user that nobody signs in as, remove them on the **Buyers** tab.

An order charges its customer's account when the customer holds one. Otherwise it charges the account the signed-in buyer buys on, when they buy on exactly one account in the store. To charge a company's account for orders its people place in some other way, see [company accounts](../dev-guide/company-accounts.md).

On the account's **Buyers** tab, choose a user, optionally enter a sublimit, and click **Add buyer**.

To remove a buyer, click **Remove**. A buyer with ledger entries or orders is deactivated instead, so their history stays on the account. A deactivated buyer has no available credit. **Reactivate** restores them, and so does adding the same user again.

A buyer's **Balance** in invoice billing is what they have charged, less refunds, plus or minus adjustments and order changes, less the payments applied to their invoice lines. In order billing it is the sum of the unpaid balances of their orders on the account. **Available** is what they can charge.

## Sublimits

A sublimit caps one buyer's balance. Leave it blank for no sublimit. A new or lowered sublimit can't be below the buyer's current balance.

The account's **Protect sublimits** setting decides whether a buyer's sublimit is also protected for only them to spend.

### Sublimits not protected

A sublimit is the highest balance one buyer can have, and every buyer spends from the same credit limit. With a $10,000 limit and a $2,000 sublimit for one buyer, another buyer can spend $9,000 first and leave that buyer only $1,000. Sublimits can add up to more than the limit.

### Sublimits protected

A sublimit is still the highest balance one buyer can have, and that amount is also protected for only them to spend. With a $10,000 limit and a $2,000 sublimit for one buyer, the other buyers share $8,000, and the $2,000 is there for that buyer however much the others spend. Protected sublimits can't add up to more than the credit limit, unless the account has unlimited credit.

The plugin settings don't accept **Protect sublimits** turned on as the default while an account that uses the default has sublimits adding up to more than its limit, and name those accounts.

### When credit reopens

A buyer's credit reopens when you apply a payment to their invoice line, or in order billing to their order. Money received but not yet applied leaves every buyer's credit as it was. For applying payments, see [payments](./payments.md).

## Adjustments

An adjustment changes the balance of the account or one buyer without an order: a fee, a correction, or an opening balance. On the account's **Ledger** tab, click **Add adjustment**. Enter an amount, choose the buyer or **Account**, enter a reason, and click **Add adjustment**. A positive amount raises the balance, and a negative one lowers it. Adjustments go on the next invoice. Order billing has no adjustments, since an account's balance there is the sum of its orders' balances; edit the order instead.
