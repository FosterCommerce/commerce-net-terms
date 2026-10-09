<?php

declare(strict_types=1);

namespace fostercommerce\netterms\records;

use craft\db\ActiveRecord;
use fostercommerce\netterms\db\Table;

/**
 * Payment application record.
 *
 * @property int $id
 * @property int $paymentId
 * @property int|null $invoiceLineId
 * @property int|null $orderId
 * @property int|null $transactionId
 * @property string $amount
 * @property string|null $dateReversed
 * @property int|null $authorId
 */
class ApplicationRecord extends ActiveRecord
{
	public static function tableName(): string
	{
		return Table::APPLICATIONS;
	}
}
