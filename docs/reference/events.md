# Events

## Accounts::EVENT_RESOLVE_HOLDER

**Fires:** when the plugin works out which account an order charges: before offering the gateway, before charging, and when the control panel shows a **Payment terms (Net Terms)** field on an order with no linked account.

**Payload:** `fostercommerce\netterms\events\ResolveHolderEvent`

| Property | Type | Notes |
|---|---|---|
| `order` | `Order` | The order being paid. |
| `holderId` | `int\|null` | The user whose account the order charges. Defaults to the order's customer when they hold an account, otherwise to the holder of the one account the signed-in buyer buys on. Set `null` for no account. |

**Listen:**

```php
use fostercommerce\netterms\events\ResolveHolderEvent;
use fostercommerce\netterms\services\Accounts;
use yii\base\Event;

Event::on(Accounts::class, Accounts::EVENT_RESOLVE_HOLDER, function (ResolveHolderEvent $event) {
	$event->holderId = MyCompanies::accountUserIdForOrder($event->order);
});
```

**Common use:** charging a company's account for orders its members place.

## Accounts::EVENT_RESOLVE_BUYER

**Fires:** after the plugin resolves the account, to work out which buyer is charging the order.

**Payload:** `fostercommerce\netterms\events\ResolveBuyerEvent`

| Property | Type | Notes |
|---|---|---|
| `order` | `Order` | The order being paid. |
| `account` | `Account` | The resolved account. |
| `userId` | `int\|null` | The user charging the order. Defaults to the signed-in user. |

**Listen:**

```php
use fostercommerce\netterms\events\ResolveBuyerEvent;
use fostercommerce\netterms\services\Accounts;
use yii\base\Event;

Event::on(Accounts::class, Accounts::EVENT_RESOLVE_BUYER, function (ResolveBuyerEvent $event) {
	$event->userId = MyOrders::placedByUserId($event->order);
});
```

**Common use:** charging the person who placed an order when someone else pays for it.

## Accounts::EVENT_DEFINE_ACCOUNT_ACCESS

**Fires:** when the storefront checks whether a user can manage buyers and sublimits on an account, and when `craft.netTerms` checks whether to show an account to a user who isn't its holder or an active buyer.

**Payload:** `fostercommerce\netterms\events\DefineAccountAccessEvent`

| Property | Type | Notes |
|---|---|---|
| `account` | `Account` | The account. |
| `user` | `User` | The user asking. |
| `canManage` | `bool` | Defaults to whether the user is the holder. |

**Listen:**

```php
use fostercommerce\netterms\events\DefineAccountAccessEvent;
use fostercommerce\netterms\services\Accounts;
use yii\base\Event;

Event::on(Accounts::class, Accounts::EVENT_DEFINE_ACCOUNT_ACCESS, function (DefineAccountAccessEvent $event) {
	$event->canManage = MyCompanies::isAdmin($event->user, $event->account->holderId);
});
```

**Common use:** letting a company's admins manage its account.

## Accounts::EVENT_DEFINE_BUYER_ELIGIBILITY

**Fires:** when someone who manages an account adds a buyer from the storefront.

**Payload:** `fostercommerce\netterms\events\DefineBuyerEligibilityEvent`

| Property | Type | Notes |
|---|---|---|
| `account` | `Account` | The account. |
| `user` | `User` | The user being added. |
| `isEligible` | `bool` | Defaults to `true`. Set `false` to refuse the user with a 403. |

**Listen:**

```php
use fostercommerce\netterms\events\DefineBuyerEligibilityEvent;
use fostercommerce\netterms\services\Accounts;
use yii\base\Event;

Event::on(Accounts::class, Accounts::EVENT_DEFINE_BUYER_ELIGIBILITY, function (DefineBuyerEligibilityEvent $event) {
	$event->isEligible = MyCompanies::isMember($event->user, $event->account->holderId);
});
```

**Common use:** letting a company's admins add only the company's own people.

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
