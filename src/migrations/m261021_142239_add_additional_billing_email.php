<?php

declare(strict_types=1);

namespace fostercommerce\netterms\migrations;

use craft\db\Migration;
use fostercommerce\netterms\db\Table;

class m261021_142239_add_additional_billing_email extends Migration
{
	public function safeUp(): bool
	{
		$this->addColumn(Table::ACCOUNTS, 'additionalBillingEmail', (string) $this->string()->after('paymentTerms'));
		$this->addColumn(Table::ACCOUNTS, 'sendInvoicesToHolder', (string) $this->boolean()->notNull()->defaultValue(true)->after('additionalBillingEmail'));
		$this->addColumn(Table::ACCOUNTS, 'sendInvoicesToAdditionalBillingEmail', (string) $this->boolean()->notNull()->defaultValue(true)->after('sendInvoicesToHolder'));

		return true;
	}
}
