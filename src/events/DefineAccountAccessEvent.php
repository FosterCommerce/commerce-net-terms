<?php

declare(strict_types=1);

namespace fostercommerce\netterms\events;

use craft\elements\User;
use fostercommerce\netterms\models\Account;
use yii\base\Event;

class DefineAccountAccessEvent extends Event
{
	public Account $account;

	public User $user;

	/**
	 * @var bool Whether the user can manage buyers and sublimits on the storefront. Defaults to whether the user is the holder.
	 */
	public bool $canManage = false;
}
