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
 * @property string $title
 * @property string|null $description
 * @property string|null $creditLimit
 * @property string|null $sublimitMode
 * @property int|null $paymentTerms
 * @property string|null $billingEmail
 * @property string $status
 * @property string $dateCreated
 * @property string $dateUpdated
 */
class AccountRecord extends ActiveRecord
{
	public static function tableName(): string
	{
		return Table::ACCOUNTS;
	}
}
