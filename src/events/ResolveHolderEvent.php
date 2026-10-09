<?php

declare(strict_types=1);

namespace fostercommerce\netterms\events;

use craft\commerce\elements\Order;
use yii\base\Event;

class ResolveHolderEvent extends Event
{
	public Order $order;

	/**
	 * @var int|null The user whose account the order charges. Defaults to the order’s customer when they hold an account, otherwise to the holder of the one account the signed-in user buys on.
	 */
	public ?int $holderId = null;
}
