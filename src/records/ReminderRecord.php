<?php

declare(strict_types=1);

namespace fostercommerce\netterms\records;

use craft\db\ActiveRecord;
use fostercommerce\netterms\db\Table;

/**
 * Reminder record.
 *
 * @property int $id
 * @property string $type
 * @property int $days
 * @property int|null $emailId
 * @property int|null $invoiceId
 * @property int|null $orderId
 */
class ReminderRecord extends ActiveRecord
{
	public static function tableName(): string
	{
		return Table::REMINDERS;
	}
}
