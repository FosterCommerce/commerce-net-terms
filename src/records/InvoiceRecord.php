<?php

declare(strict_types=1);

namespace fostercommerce\netterms\records;

use craft\db\ActiveRecord;
use fostercommerce\netterms\db\Table;

/**
 * Invoice record.
 *
 * @property int $id
 * @property int $accountId
 * @property string $number
 * @property string|null $dateIssued
 * @property string $dateDue
 * @property string|null $dateVoided
 * @property string|null $notes
 */
class InvoiceRecord extends ActiveRecord
{
	public static function tableName(): string
	{
		return Table::INVOICES;
	}
}
