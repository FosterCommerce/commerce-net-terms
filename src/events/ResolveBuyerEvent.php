<?php

declare(strict_types=1);

namespace fostercommerce\netterms\events;

use craft\commerce\elements\Order;
use fostercommerce\netterms\models\Account;
use yii\base\Event;

class ResolveBuyerEvent extends Event
{
	public Order $order;

	public Account $account;

	/**
	 * @var int|null The user charging the order. Defaults to the signed-in user.
	 */
	public ?int $userId = null;
}
