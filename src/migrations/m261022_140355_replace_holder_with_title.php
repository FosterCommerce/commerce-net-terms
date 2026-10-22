<?php

declare(strict_types=1);

namespace fostercommerce\netterms\migrations;

use craft\db\Migration;
use craft\db\Query;
use craft\db\Table as CraftTable;
use fostercommerce\netterms\db\Table;

/**
 * Name and describe each account instead of linking a holder user, and send account emails to one optional billing email.
 */
class m261022_140355_replace_holder_with_title extends Migration
{
	public function safeUp(): bool
	{
		$this->addColumn(Table::ACCOUNTS, 'title', (string) $this->string()->after('storeId'));
		$this->addColumn(Table::ACCOUNTS, 'description', (string) $this->text()->after('title'));
		$this->renameColumn(Table::ACCOUNTS, 'additionalBillingEmail', 'billingEmail');

		/** @var array<int, array{id: int, billingEmail: string|null, sendInvoicesToHolder: int|bool, sendInvoicesToAdditionalBillingEmail: int|bool, fullName: string|null, username: string, email: string}> $accountRows */
		$accountRows = (new Query())
			->select(['accounts.id', 'accounts.billingEmail', 'accounts.sendInvoicesToHolder', 'accounts.sendInvoicesToAdditionalBillingEmail', 'users.fullName', 'users.username', 'users.email'])
			->from([
				'accounts' => Table::ACCOUNTS,
			])
			->innerJoin([
				'users' => CraftTable::USERS,
			], '[[users.id]] = [[accounts.holderId]]')
			->all();

		foreach ($accountRows as $accountRow) {
			// Keep the address the account chose, preferring the additional one when it chose both
			$billingEmail = match (true) {
				$accountRow['billingEmail'] !== null && (bool) $accountRow['sendInvoicesToAdditionalBillingEmail'] => $accountRow['billingEmail'],
				(bool) $accountRow['sendInvoicesToHolder'] => $accountRow['email'],
				default => $accountRow['billingEmail'],
			};

			$this->update(Table::ACCOUNTS, [
				'title' => $accountRow['fullName'] ?: $accountRow['username'],
				'billingEmail' => $billingEmail,
			], [
				'id' => $accountRow['id'],
			]);
		}

		$this->alterColumn(Table::ACCOUNTS, 'title', (string) $this->string()->notNull());
		$this->dropForeignKeyIfExists(Table::ACCOUNTS, ['holderId']);

		// Index the store first, since the store foreign key needs an index on MySQL
		$this->createIndex(null, Table::ACCOUNTS, ['storeId'], false);
		$this->dropIndexIfExists(Table::ACCOUNTS, ['storeId', 'holderId'], true);
		$this->dropColumn(Table::ACCOUNTS, 'holderId');
		$this->dropColumn(Table::ACCOUNTS, 'sendInvoicesToHolder');
		$this->dropColumn(Table::ACCOUNTS, 'sendInvoicesToAdditionalBillingEmail');

		$this->renameColumn(Table::EMAILS, 'sendToHolder', 'sendToBillingEmail');

		return true;
	}
}
