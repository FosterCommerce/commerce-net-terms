<?php

declare(strict_types=1);

namespace fostercommerce\netterms\records;

use craft\db\ActiveRecord;
use fostercommerce\netterms\db\Table;

/**
 * Payment record.
 *
 * @property int $id
 * @property int $accountId
 * @property string $amount
 * @property string $method
 * @property string|null $reference
 * @property string $dateReceived
 * @property string|null $note
 * @property int|null $authorId
 */
class PaymentRecord extends ActiveRecord
{
	public static function tableName(): string
	{
		return Table::PAYMENTS;
	}
}
