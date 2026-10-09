<?php

declare(strict_types=1);

namespace fostercommerce\netterms\records;

use craft\db\ActiveRecord;
use fostercommerce\netterms\db\Table;

/**
 * Buyer record.
 *
 * @property int $id
 * @property int $accountId
 * @property int $userId
 * @property string|null $sublimit
 * @property bool $active
 */
class BuyerRecord extends ActiveRecord
{
	public static function tableName(): string
	{
		return Table::BUYERS;
	}
}
