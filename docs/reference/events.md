# Events

## Accounts::EVENT_RESOLVE_BUYER

**Fires:** when the plugin works out which accounts an order can charge: before showing the gateway, when rendering its payment form, before charging, and when the control panel shows a **Payment terms (Net Terms)** field on an order with no linked account.

**Payload:** `fostercommerce\netterms\events\ResolveBuyerEvent`

| Property | Type | Notes |
|---|---|---|
| `order` | `Order` | The order being paid. |
| `userId` | `int\|null` | The user charging the order. The order can charge each account this user is an active buyer on in the order's store. Defaults to the order's customer, or on the storefront to `null` unless the customer is signed in. Set `null` to refuse Net Terms for the order. |

**Listen:**

```php
use fostercommerce\netterms\events\ResolveBuyerEvent;
use fostercommerce\netterms\services\Accounts;
use yii\base\Event;

Event::on(Accounts::class, Accounts::EVENT_RESOLVE_BUYER, function (ResolveBuyerEvent $event) {
	// Refuse Net Terms for guests and personal orders
	if ($event->userId !== null) {
		$event->userId = MyCompanies::companyForOrder($event->order)?->accountUserId;
	}
});
```

**Common use:** charging every order a company places to one user that stands for the company.

## Accounts::EVENT_DEFINE_ACCOUNT_ACCESS

**Fires:** when `net-terms/storefront/save-buyer`, `net-terms/storefront/remove-buyer` or `craft.netTerms.canManage()` checks whether a user can manage an account's buyers and sublimits, and when `craft.netTerms` or `net-terms/storefront/invoice-pdf` checks whether a user can see an account.

**Payload:** `fostercommerce\netterms\events\DefineAccountAccessEvent`

| Property | Type | Notes |
|---|---|---|
| `account` | `Account` | The account. |
| `user` | `User` | The user asking. |
| `canManage` | `bool` | Whether the user can manage buyers and sublimits. Defaults to `false`. |
| `canView` | `bool` | Whether `craft.netTerms` returns the account's figures, buyers, invoices and orders to the user, and whether `net-terms/storefront/invoice-pdf` serves its invoice PDFs. Defaults to whether the user is an active buyer. A user who can manage the account can always see it. |

**Listen:**

```php
use fostercommerce\netterms\events\DefineAccountAccessEvent;
use fostercommerce\netterms\services\Accounts;
use yii\base\Event;

Event::on(Accounts::class, Accounts::EVENT_DEFINE_ACCOUNT_ACCESS, function (DefineAccountAccessEvent $event) {
	$event->canManage = MyCompanies::isAdmin($event->user, $event->account->id);
	$event->canView = MyCompanies::isMember($event->user, $event->account->id);
});
```

**Common use:** letting a company's admins manage its account, and letting other company members see it without buying on it.

## Accounts::EVENT_DEFINE_BUYER_ELIGIBILITY

**Fires:** when someone who manages an account adds a buyer from the storefront.

**Payload:** `fostercommerce\netterms\events\DefineBuyerEligibilityEvent`

| Property | Type | Notes |
|---|---|---|
| `account` | `Account` | The account. |
| `user` | `User` | The user being added. |
| `isEligible` | `bool` | Defaults to `false`, which refuses the user with a 403. Set `true` to allow them. |

**Listen:**

```php
use fostercommerce\netterms\events\DefineBuyerEligibilityEvent;
use fostercommerce\netterms\services\Accounts;
use yii\base\Event;

Event::on(Accounts::class, Accounts::EVENT_DEFINE_BUYER_ELIGIBILITY, function (DefineBuyerEligibilityEvent $event) {
	$event->isEligible = MyCompanies::isMember($event->user, $event->account->id);
});
```

**Common use:** letting a company's admins add only the company's own people. Without a handler, `net-terms/storefront/save-buyer` doesn't add buyers.

## Ledger::EVENT_AFTER_ADD_ENTRY

**Fires:** after a ledger entry is written and its database transaction commits, in invoice billing. The event does not fire for an entry whose database transaction rolls back.

**Payload:** `fostercommerce\netterms\events\EntryEvent`

| Property | Type | Notes |
|---|---|---|
| `entry` | `Entry` | The entry: its `type`, an `EntryType` enum whose `value` is `charge`, `refund`, `adjustment`, `payment`, `reversal`, or `orderChange`; its `amount`, a signed `Money`; and its `buyerId` and `orderId`. |

**Listen:**

```php
use fostercommerce\netterms\events\EntryEvent;
use fostercommerce\netterms\services\Ledger;
use yii\base\Event;

Event::on(Ledger::class, Ledger::EVENT_AFTER_ADD_ENTRY, function (EntryEvent $event) {
	MyAccounting::postEntry($event->entry);
});
```

**Common use:** mirroring the ledger to an accounting system. In order billing, listen to Commerce's transaction events instead.

## Invoices::EVENT_AFTER_ISSUE_INVOICE

**Fires:** after an invoice is issued, before it is emailed.

**Payload:** `fostercommerce\netterms\events\InvoiceEvent`

| Property | Type | Notes |
|---|---|---|
| `invoice` | `Invoice` | The issued invoice. |

**Listen:**

```php
use fostercommerce\netterms\events\InvoiceEvent;
use fostercommerce\netterms\services\Invoices;
use yii\base\Event;

Event::on(Invoices::class, Invoices::EVENT_AFTER_ISSUE_INVOICE, function (InvoiceEvent $event) {
	MyAccounting::createInvoice($event->invoice);
});
```

**Common use:** creating the matching invoice in an accounting system.

## Reversals in Commerce's transaction events

In order billing, reversing an application writes a successful refund transaction to the order, though no money goes back to the payer. Its `code` is `net-terms-reversal`, the constant `CreditOrders::REVERSAL_TRANSACTION_CODE`. A module that acts on refunds through `Transactions::EVENT_AFTER_SAVE_TRANSACTION` can skip a transaction with that code.

```php
use craft\commerce\events\TransactionEvent;
use craft\commerce\services\Transactions;
use fostercommerce\netterms\services\CreditOrders;
use yii\base\Event;

Event::on(Transactions::class, Transactions::EVENT_AFTER_SAVE_TRANSACTION, function (TransactionEvent $event) {
	if ($event->transaction->code === CreditOrders::REVERSAL_TRANSACTION_CODE) {
		return;
	}

	// Handle a refund that returned money
});
```
