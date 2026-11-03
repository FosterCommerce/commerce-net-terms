<?php

namespace fostercommerce\netterms\migrations;

use craft\db\Migration;
use fostercommerce\netterms\db\Table;

/**
 * m261103_121250_partial_payment_authorizations migration.
 */
class m261103_121250_partial_payment_authorizations extends Migration
{
	public function safeUp(): bool
	{
		$this->addColumn(Table::ENTRIES, 'status', $this->string(20)->notNull()->defaultValue('posted'));
		$this->addColumn(Table::ENTRIES, 'authorizationHash', $this->string(32));
		$this->createIndex(null, Table::ENTRIES, ['authorizationHash'], true);

		return true;
	}

	public function safeDown(): bool
	{
		echo "m261103_121250_partial_payment_authorizations cannot be reverted.\n";
		return false;
	}
}
