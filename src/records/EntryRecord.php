<?php

declare(strict_types=1);

namespace fostercommerce\netterms\records;

use craft\db\ActiveRecord;
use fostercommerce\netterms\db\Table;

/**
 * Ledger entry record.
 *
 * @property int $id
 * @property int $accountId
 * @property int|null $buyerId
 * @property string $type
 * @property string $amount
 * @property int|null $orderId
 * @property string|null $transactionHash
 * @property int|null $invoiceLineId
 * @property int|null $applicationId
 * @property string|null $note
 * @property int|null $authorId
 * @property string $dateCreated
 */
class EntryRecord extends ActiveRecord
{
	public static function tableName(): string
	{
		return Table::ENTRIES;
	}
}
