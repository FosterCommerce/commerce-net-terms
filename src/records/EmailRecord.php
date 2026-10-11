<?php

declare(strict_types=1);

namespace fostercommerce\netterms\records;

use craft\db\ActiveRecord;
use fostercommerce\netterms\db\Table;

/**
 * Email record.
 *
 * @property int $id
 * @property string $name
 * @property string $trigger
 * @property int|null $days
 * @property bool $enabled
 * @property bool $sendToHolder
 * @property bool $sendToBuyers
 * @property string|null $ccAddresses
 * @property string|null $bccAddresses
 * @property string|null $replyTo
 * @property string|null $senderName
 * @property string|null $senderAddress
 * @property string $subject
 * @property string $body
 * @property bool $attachPdf
 */
class EmailRecord extends ActiveRecord
{
	public static function tableName(): string
	{
		return Table::EMAILS;
	}
}
