<?php

declare(strict_types=1);

namespace fostercommerce\netterms\migrations;

use Craft;
use craft\db\Migration;
use craft\db\Query;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\services\ProjectConfig;
use fostercommerce\netterms\db\Table;
use fostercommerce\netterms\enums\Billing;
use fostercommerce\netterms\enums\EmailTrigger;
use fostercommerce\netterms\Plugin;

/**
 * Store invoice-billing emails in the database so admins can edit them on any environment, and record which email sent each invoice reminder.
 */
class m261011_163546_add_emails extends Migration
{
	public function safeUp(): bool
	{
		$this->createTable(Table::EMAILS, [
			'id' => $this->primaryKey(),
			'name' => $this->string()->notNull(),
			'trigger' => $this->string(20)->notNull(),
			'days' => $this->integer(),
			'enabled' => $this->boolean()->notNull()->defaultValue(true),
			'sendToHolder' => $this->boolean()->notNull()->defaultValue(true),
			'sendToBuyers' => $this->boolean()->notNull()->defaultValue(false),
			'ccAddresses' => $this->text(),
			'bccAddresses' => $this->text(),
			'replyTo' => $this->string(),
			'senderName' => $this->string(),
			'senderAddress' => $this->string(),
			'subject' => $this->text()->notNull(),
			'body' => $this->text()->notNull(),
			'attachPdf' => $this->boolean()->notNull()->defaultValue(false),
			'dateCreated' => $this->dateTime()->notNull(),
			'dateUpdated' => $this->dateTime()->notNull(),
			'uid' => $this->uid(),
		]);

		$this->addColumn(Table::REMINDERS, 'emailId', (string) $this->integer()->after('days'));
		$this->addForeignKey(null, Table::REMINDERS, ['emailId'], Table::EMAILS, ['id'], 'CASCADE');

		// Key sent invoice reminders by email and schedule, so two emails on one schedule both send, and a rescheduled email sends again
		$this->dropIndexIfExists(Table::REMINDERS, ['type', 'days', 'invoiceId'], true);
		$this->createIndex(null, Table::REMINDERS, ['emailId', 'type', 'days', 'invoiceId'], true);

		$plugin = Plugin::getInstance();
		$reminders = null;

		// Read the settings from project config, since the loaded settings predate the previous migration's conversion
		$settings = Craft::$app->getProjectConfig()->get(ProjectConfig::PATH_PLUGINS . '.' . Plugin::HANDLE . '.settings');
		$settings = is_array($settings) ? $settings : [];

		$savedReminders = is_array($settings['reminders'] ?? null) ? ProjectConfigHelper::unpackAssociativeArrays($settings['reminders']) : [];

		// Read the 1.0.0 days on an environment whose project config YAML was converted elsewhere, since this one's database still holds them
		if ($savedReminders === []) {
			foreach ([
				'dueSoon' => 'dueSoonReminderDays',
				'overdue' => 'overdueReminderDays',
			] as $type => $legacyKey) {
				if (is_numeric($settings[$legacyKey] ?? null)) {
					$savedReminders[] = [
						'days' => (int) $settings[$legacyKey],
						'enabled' => true,
						'type' => $type,
					];
				}
			}
		}

		// Convert an invoice-billing site's reminders to emails, so they keep sending
		if ($plugin->getBilling() === Billing::Invoices && $savedReminders !== []) {
			$reminders = array_map(static fn (array $reminder): array => [
				'trigger' => EmailTrigger::from($reminder['type']),
				'days' => (int) $reminder['days'],
				'enabled' => (bool) $reminder['enabled'],
			], array_values($savedReminders));
		}

		$plugin->getEmails()->createStarterEmails($reminders);

		// Link sent reminders to their email, so the new index stops a resend on the day of the update
		/** @var array<int, array{id: int, trigger: string, days: int}> $reminderEmails */
		$reminderEmails = (new Query())
			->select(['id', 'trigger', 'days'])
			->from(Table::EMAILS)
			->where(['not', [
				'trigger' => EmailTrigger::Issued->value,
			]])
			->all();

		foreach ($reminderEmails as $reminderEmail) {
			$this->update(Table::REMINDERS, [
				'emailId' => $reminderEmail['id'],
			], [
				'and',
				[
					'emailId' => null,
					'type' => $reminderEmail['trigger'],
					'days' => $reminderEmail['days'],
				],
				['not', [
					'invoiceId' => null,
				]],
			]);
		}

		return true;
	}
}
