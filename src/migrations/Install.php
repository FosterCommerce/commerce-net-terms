<?php

declare(strict_types=1);

namespace fostercommerce\netterms\migrations;

use craft\commerce\db\Table as CommerceTable;
use craft\db\Migration;
use craft\db\Table as CraftTable;
use fostercommerce\netterms\db\Table;
use fostercommerce\netterms\enums\AccountStatus;

class Install extends Migration
{
	public function safeUp(): bool
	{
		$this->createTables();
		$this->createIndexes();
		$this->addForeignKeys();

		return true;
	}

	public function safeDown(): bool
	{
		$this->dropTableIfExists(Table::REMINDERS);
		$this->dropTableIfExists(Table::ORDERS);
		$this->dropTableIfExists(Table::ENTRIES);
		$this->dropTableIfExists(Table::APPLICATIONS);
		$this->dropTableIfExists(Table::PAYMENTS);
		$this->dropTableIfExists(Table::INVOICE_LINES);
		$this->dropTableIfExists(Table::INVOICES);
		$this->dropTableIfExists(Table::BUYERS);
		$this->dropTableIfExists(Table::ACCOUNTS);

		return true;
	}

	private function createTables(): void
	{
		$this->createTable(Table::ACCOUNTS, [
			'id' => $this->primaryKey(),
			'storeId' => $this->integer()->notNull(),
			'holderId' => $this->integer()->notNull(),
			// Null for unlimited credit
			'creditLimit' => $this->decimal(14, 4),
			'sublimitMode' => $this->string(20),
			'paymentTerms' => $this->integer(),
			'status' => $this->string(20)->notNull()->defaultValue(AccountStatus::Active->value),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createTable(Table::BUYERS, [
			'id' => $this->primaryKey(),
			'accountId' => $this->integer()->notNull(),
			'userId' => $this->integer()->notNull(),
			'sublimit' => $this->decimal(14, 4),
			'active' => $this->boolean()->notNull()->defaultValue(true),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createTable(Table::INVOICES, [
			'id' => $this->primaryKey(),
			'accountId' => $this->integer()->notNull(),
			'number' => $this->string(32)->notNull(),
			'dateIssued' => $this->dateTime()->notNull(),
			'dateDue' => $this->dateTime()->notNull(),
			'dateVoided' => $this->dateTime(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createTable(Table::INVOICE_LINES, [
			'id' => $this->primaryKey(),
			'invoiceId' => $this->integer()->notNull(),
			'buyerId' => $this->integer(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		// Order billing: which account and buyer each order charged. The Commerce order records what it owes.
		$this->createTable(Table::ORDERS, [
			'id' => $this->primaryKey(),
			'orderId' => $this->integer()->notNull(),
			'accountId' => $this->integer()->notNull(),
			'buyerId' => $this->integer()->notNull(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		// One row per reminder sent, so each is sent once per invoice or order
		$this->createTable(Table::REMINDERS, [
			'id' => $this->primaryKey(),
			'type' => $this->string(20)->notNull(),
			'invoiceId' => $this->integer(),
			'orderId' => $this->integer(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createTable(Table::PAYMENTS, [
			'id' => $this->primaryKey(),
			'accountId' => $this->integer()->notNull(),
			'amount' => $this->decimal(14, 4)->notNull(),
			'method' => $this->string(20)->notNull(),
			'reference' => $this->string(),
			'dateReceived' => $this->dateTime()->notNull(),
			'note' => $this->text(),
			'authorId' => $this->integer(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createTable(Table::APPLICATIONS, [
			'id' => $this->primaryKey(),
			'paymentId' => $this->integer()->notNull(),
			// Invoice billing applies to an invoice line, order billing to an order and the Commerce transaction recording it
			'invoiceLineId' => $this->integer(),
			'orderId' => $this->integer(),
			'transactionId' => $this->integer(),
			'amount' => $this->decimal(14, 4)->notNull(),
			'dateReversed' => $this->dateTime(),
			'authorId' => $this->integer(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->createTable(Table::ENTRIES, [
			'id' => $this->primaryKey(),
			'accountId' => $this->integer()->notNull(),
			'buyerId' => $this->integer(),
			'type' => $this->string(20)->notNull(),
			// Positive raises what the account owes
			'amount' => $this->decimal(14, 4)->notNull(),
			'orderId' => $this->integer(),
			'transactionHash' => $this->string(32),
			'invoiceLineId' => $this->integer(),
			'applicationId' => $this->integer(),
			'note' => $this->text(),
			'authorId' => $this->integer(),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);
	}

	private function createIndexes(): void
	{
		$this->createIndex(null, Table::ACCOUNTS, ['storeId', 'holderId'], true);
		$this->createIndex(null, Table::BUYERS, ['accountId', 'userId'], true);
		$this->createIndex(null, Table::BUYERS, ['userId'], false);
		$this->createIndex(null, Table::INVOICES, ['number'], true);
		$this->createIndex(null, Table::INVOICES, ['accountId'], false);
		$this->createIndex(null, Table::INVOICE_LINES, ['invoiceId'], false);
		$this->createIndex(null, Table::PAYMENTS, ['accountId'], false);
		$this->createIndex(null, Table::REMINDERS, ['type', 'invoiceId'], true);
		$this->createIndex(null, Table::REMINDERS, ['type', 'orderId'], true);
		$this->createIndex(null, Table::ORDERS, ['orderId'], true);
		$this->createIndex(null, Table::ORDERS, ['accountId'], false);
		$this->createIndex(null, Table::APPLICATIONS, ['paymentId'], false);
		$this->createIndex(null, Table::APPLICATIONS, ['invoiceLineId'], false);
		$this->createIndex(null, Table::APPLICATIONS, ['orderId'], false);
		$this->createIndex(null, Table::APPLICATIONS, ['transactionId'], false);
		$this->createIndex(null, Table::ENTRIES, ['accountId', 'buyerId'], false);
		$this->createIndex(null, Table::ENTRIES, ['invoiceLineId'], false);
		$this->createIndex(null, Table::ENTRIES, ['orderId'], false);
		$this->createIndex(null, Table::ENTRIES, ['transactionHash'], false);
	}

	private function addForeignKeys(): void
	{
		$this->addForeignKey(null, Table::ACCOUNTS, ['storeId'], CommerceTable::STORES, ['id'], 'CASCADE');
		$this->addForeignKey(null, Table::ACCOUNTS, ['holderId'], CraftTable::USERS, ['id'], 'CASCADE');

		$this->addForeignKey(null, Table::BUYERS, ['accountId'], Table::ACCOUNTS, ['id'], 'CASCADE');
		$this->addForeignKey(null, Table::BUYERS, ['userId'], CraftTable::USERS, ['id'], 'CASCADE');

		$this->addForeignKey(null, Table::INVOICES, ['accountId'], Table::ACCOUNTS, ['id'], 'CASCADE');

		$this->addForeignKey(null, Table::INVOICE_LINES, ['invoiceId'], Table::INVOICES, ['id'], 'CASCADE');
		$this->addForeignKey(null, Table::INVOICE_LINES, ['buyerId'], Table::BUYERS, ['id'], 'SET NULL');

		$this->addForeignKey(null, Table::PAYMENTS, ['accountId'], Table::ACCOUNTS, ['id'], 'CASCADE');
		$this->addForeignKey(null, Table::PAYMENTS, ['authorId'], CraftTable::USERS, ['id'], 'SET NULL');

		$this->addForeignKey(null, Table::REMINDERS, ['invoiceId'], Table::INVOICES, ['id'], 'CASCADE');
		$this->addForeignKey(null, Table::REMINDERS, ['orderId'], CommerceTable::ORDERS, ['id'], 'CASCADE');
		$this->addForeignKey(null, Table::ORDERS, ['orderId'], CommerceTable::ORDERS, ['id'], 'CASCADE');
		$this->addForeignKey(null, Table::ORDERS, ['accountId'], Table::ACCOUNTS, ['id'], 'CASCADE');
		$this->addForeignKey(null, Table::ORDERS, ['buyerId'], Table::BUYERS, ['id'], 'CASCADE');
		$this->addForeignKey(null, Table::APPLICATIONS, ['paymentId'], Table::PAYMENTS, ['id'], 'CASCADE');
		$this->addForeignKey(null, Table::APPLICATIONS, ['invoiceLineId'], Table::INVOICE_LINES, ['id'], 'CASCADE');
		$this->addForeignKey(null, Table::APPLICATIONS, ['orderId'], CommerceTable::ORDERS, ['id'], 'CASCADE');
		$this->addForeignKey(null, Table::APPLICATIONS, ['transactionId'], CommerceTable::TRANSACTIONS, ['id'], 'SET NULL');
		$this->addForeignKey(null, Table::APPLICATIONS, ['authorId'], CraftTable::USERS, ['id'], 'SET NULL');

		$this->addForeignKey(null, Table::ENTRIES, ['accountId'], Table::ACCOUNTS, ['id'], 'CASCADE');
		// Keep ledger rows when their buyer is deleted, since they record money owed or paid
		$this->addForeignKey(null, Table::ENTRIES, ['buyerId'], Table::BUYERS, ['id'], 'SET NULL');
		$this->addForeignKey(null, Table::ENTRIES, ['orderId'], CommerceTable::ORDERS, ['id'], 'CASCADE');
		$this->addForeignKey(null, Table::ENTRIES, ['invoiceLineId'], Table::INVOICE_LINES, ['id'], 'SET NULL');
		$this->addForeignKey(null, Table::ENTRIES, ['applicationId'], Table::APPLICATIONS, ['id'], 'SET NULL');
		$this->addForeignKey(null, Table::ENTRIES, ['authorId'], CraftTable::USERS, ['id'], 'SET NULL');
	}
}
