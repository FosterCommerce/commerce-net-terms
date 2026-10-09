# Company accounts

By default an order charges the account its customer holds, or else the one account the signed-in buyer buys on. The person paying is the buyer, and only the holder can manage the account on the storefront. To charge orders to a company's account, a site module can listen to these events.

## The contract

| Event | Answers | Default |
|---|---|---|
| `Accounts::EVENT_RESOLVE_HOLDER` | Which user's account an order charges | The customer, or the holder of the one account the signed-in buyer buys on |
| `Accounts::EVENT_RESOLVE_BUYER` | Which user is charging the order | The signed-in user |
| `Accounts::EVENT_DEFINE_ACCOUNT_ACCESS` | Whether a user can manage buyers and sublimits on the storefront | The holder only |
| `Accounts::EVENT_DEFINE_BUYER_ELIGIBILITY` | Whether the storefront can add a user as a buyer | Any user |

To charge a company, make a user that represents the company the account's holder, and let the company's admins manage it.

## Example

```php
<?php

declare(strict_types=1);

namespace modules\companies;

use craft\elements\User;
use fostercommerce\netterms\events\DefineAccountAccessEvent;
use fostercommerce\netterms\events\ResolveHolderEvent;
use fostercommerce\netterms\services\Accounts;
use modules\companies\services\Companies;
use yii\base\Event;
use yii\base\Module as BaseModule;

class Module extends BaseModule
{
	public function init(): void
	{
		parent::init();

		// Charge the company's account for orders its members place
		Event::on(
			Accounts::class,
			Accounts::EVENT_RESOLVE_HOLDER,
			static function (ResolveHolderEvent $event): void {
				$company = Companies::companyForOrder($event->order);

				if ($company !== null) {
					$event->holderId = $company->accountUserId;
				}
			},
		);

		// Let the company's admins manage its account
		Event::on(
			Accounts::class,
			Accounts::EVENT_DEFINE_ACCOUNT_ACCESS,
			static function (DefineAccountAccessEvent $event): void {
				$holder = $event->account->getHolder();
				$company = $holder instanceof User ? Companies::companyForAccountUser($holder) : null;

				if ($company !== null) {
					$event->canManage = Companies::isAdmin($company, $event->user);
				}
			},
		);
	}
}
```

The buyer is still the signed-in user, so add each person in the company as a buyer on the company's account, with an optional sublimit.

For every property each event carries, see [events](../reference/events.md).
