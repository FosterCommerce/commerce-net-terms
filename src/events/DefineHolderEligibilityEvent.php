<?php

declare(strict_types=1);

namespace fostercommerce\netterms\events;

use craft\elements\User;
use yii\base\Event;

class DefineHolderEligibilityEvent extends Event
{
	public User $user;

	/**
	 * @var bool Whether the user can hold a new account. Defaults to true.
	 */
	public bool $isEligible = true;
}
