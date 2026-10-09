<?php

declare(strict_types=1);

namespace fostercommerce\netterms\records;

use craft\db\ActiveRecord;
use fostercommerce\netterms\db\Table;

/**
 * Credit order record.
 *
 * @property int $id
 * @property int $orderId
 * @property int $accountId
 * @property int $buyerId
 */
class CreditOrderRecord extends ActiveRecord
{
	public static function tableName(): string
	{
		return Table::ORDERS;
	}
}
