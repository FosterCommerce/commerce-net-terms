<?php

declare(strict_types=1);

namespace fostercommerce\netterms\records;

use craft\db\ActiveRecord;
use fostercommerce\netterms\db\Table;

/**
 * Invoice line record.
 *
 * @property int $id
 * @property int $invoiceId
 * @property int|null $buyerId
 */
class InvoiceLineRecord extends ActiveRecord
{
	public static function tableName(): string
	{
		return Table::INVOICE_LINES;
	}
}
