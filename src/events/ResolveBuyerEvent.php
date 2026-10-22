<?php

declare(strict_types=1);

namespace fostercommerce\netterms\events;

use craft\commerce\elements\Order;
use yii\base\Event;

class ResolveBuyerEvent extends Event
{
	public Order $order;

	/**
	 * @var int|null The user charging the order. Defaults to the order’s customer, and on the storefront only when they are signed in. Set `null` to refuse Net Terms for the order.
	 */
	public ?int $userId = null;
}
