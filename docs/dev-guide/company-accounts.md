# Company accounts

An account has buyers but no owner. To tie accounts to a company, add an Account (Net Terms) field to the company's entries, and add and remove buyers from a site module.

## The contract

| Event | Answers | Default |
|---|---|---|
| `Accounts::EVENT_RESOLVE_BUYER` | Which user is charging the order | The order's customer |
| `Accounts::EVENT_DEFINE_ACCOUNT_ACCESS` | Whether a user can see the account, and manage its buyers and sublimits, through `craft.netTerms` and the storefront actions | Manage: no one. View: active buyers |
| `Accounts::EVENT_DEFINE_BUYER_ELIGIBILITY` | Whether `net-terms/storefront/save-buyer` can add a user as a buyer | No one |

An order can charge each account in its store that the event's `userId` is an active buyer on. With more than one, the customer chooses in the payment form. On the storefront, the default user is `null` unless the customer is signed in.

## Linking a company to its accounts

Add an **Account (Net Terms)** field to the company's entry type. Each field holds one account, so a company with a separate account for each team gets one field per team. A field returns the `Account`, or `null` when no account is selected or the account no longer exists. In GraphQL, the field returns the account's ID.

```twig
{% set account = company.directorsAccount %}
```

## Syncing buyers

Net Terms doesn't add or remove buyers on its own. Call `Accounts::saveBuyer()` and `Accounts::removeBuyer()` from a module whenever a company's people change. This example assumes each user has a `company` Entries field. It puts each member of the `directors` group on the account in their company's **Directors account** field:

```php
<?php

declare(strict_types=1);

namespace modules\companies;

use Craft;
use craft\elements\User;
use craft\events\ElementEvent;
use craft\events\UserGroupsAssignEvent;
use craft\services\Elements;
use craft\services\Users;
use fostercommerce\netterms\models\Account;
use fostercommerce\netterms\models\Buyer;
use fostercommerce\netterms\Plugin as NetTerms;
use yii\base\Event;
use yii\base\Module as BaseModule;

class Module extends BaseModule
{
	public function init(): void
	{
		parent::init();

		Event::on(Elements::class, Elements::EVENT_AFTER_SAVE_ELEMENT, function (ElementEvent $event): void {
			if ($event->element instanceof User) {
				$this->syncBuyer($event->element);
			}
		});

		// Sync on group changes too, since assigning groups doesn't save the user
		Event::on(Users::class, Users::EVENT_AFTER_ASSIGN_USER_TO_GROUPS, function (UserGroupsAssignEvent $event): void {
			$user = Craft::$app->getUsers()->getUserById($event->userId);

			if ($user !== null) {
				$this->syncBuyer($user);
			}
		});
	}

	private function syncBuyer(User $user): void
	{
		$accounts = NetTerms::getInstance()->getAccounts();
		$account = $user->company->one()?->directorsAccount;

		if (! $account instanceof Account) {
			return;
		}

		$buyer = $accounts->getBuyer($account, $user->id);

		if ($user->isInGroup('directors') && $buyer?->active !== true) {
			$accounts->saveBuyer(new Buyer([
				'accountId' => $account->id,
				'userId' => $user->id,
			]));
		} elseif (! $user->isInGroup('directors') && $buyer?->active === true) {
			$accounts->removeBuyer($buyer);
		}
	}
}
```

`removeBuyer()` keeps a buyer with ledger history as an inactive buyer, and deletes one without.

The example doesn't remove a user from their old company's account, or sync a company whose account field changes. To sync existing users, resave them with `./craft resave/users`.

## One buyer for the whole company

To have every order a company places count against one buyer, make one user stand for the company, add that user as the account's only buyer, and resolve every order's buyer to that user:

```php
use fostercommerce\netterms\events\ResolveBuyerEvent;
use fostercommerce\netterms\services\Accounts;
use modules\companies\services\Companies;
use yii\base\Event;

Event::on(Accounts::class, Accounts::EVENT_RESOLVE_BUYER, static function (ResolveBuyerEvent $event): void {
	// Refuse Net Terms for guests and personal orders
	if ($event->userId !== null) {
		$event->userId = Companies::companyForOrder($event->order)?->accountUserId;
	}
});
```

Leave a `null` `userId` alone. On the storefront, a `null` user means the person paying isn't signed in as the order's customer.

Then let the company's people see the account with `EVENT_DEFINE_ACCOUNT_ACCESS`, because they aren't buyers. To send the company's invoice emails to its billing inbox, set the account's [Billing email](../user-guide/accounts-and-buyers.md#accounts).

For every property each event carries, see [events](../reference/events.md).
