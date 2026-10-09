<?php

declare(strict_types=1);

namespace fostercommerce\netterms\records;

use craft\db\ActiveRecord;
use fostercommerce\netterms\db\Table;

/**
 * Account record.
 *
 * @property int $id
 * @property int $storeId
 * @property int $holderId
 * @property string|null $creditLimit
 * @property string|null $sublimitMode
 * @property int|null $paymentTerms
 * @property string $status
 */
class AccountRecord extends ActiveRecord
{
	public static function tableName(): string
	{
		return Table::ACCOUNTS;
	}
}
