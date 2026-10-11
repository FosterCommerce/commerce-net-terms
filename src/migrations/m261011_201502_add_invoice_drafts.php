<?php

declare(strict_types=1);

namespace fostercommerce\netterms\migrations;

use craft\db\Migration;
use fostercommerce\netterms\db\Table;

/**
 * Let an invoice be saved as a draft, with no issue date, and give invoices notes.
 */
class m261011_201502_add_invoice_drafts extends Migration
{
	public function safeUp(): bool
	{
		$this->alterColumn(Table::INVOICES, 'dateIssued', (string) $this->dateTime()->null());
		$this->addColumn(Table::INVOICES, 'notes', (string) $this->text()->after('dateVoided'));

		return true;
	}
}
