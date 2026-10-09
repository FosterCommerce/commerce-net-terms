<?php

declare(strict_types=1);

namespace fostercommerce\netterms\events;

use craft\elements\User;
use fostercommerce\netterms\models\Account;
use yii\base\Event;

class DefineBuyerEligibilityEvent extends Event
{
	public Account $account;

	public User $user;

	/**
	 * @var bool Whether the storefront can add the user as a buyer on the account. Defaults to true.
	 */
	public bool $isEligible = true;
}
