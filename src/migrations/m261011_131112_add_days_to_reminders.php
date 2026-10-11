<?php

declare(strict_types=1);

namespace fostercommerce\netterms\migrations;

use Craft;
use craft\db\Migration;
use craft\helpers\ProjectConfig as ProjectConfigHelper;
use craft\services\ProjectConfig;
use fostercommerce\netterms\db\Table;
use fostercommerce\netterms\Plugin;

/**
 * Until 2.0.0 each reminder type had one days setting, so record the days of each sent reminder to allow more than one reminder per type.
 */
class m261011_131112_add_days_to_reminders extends Migration
{
	private const LEGACY_KEYS = [
		'dueSoon' => 'dueSoonReminderDays',
		'overdue' => 'overdueReminderDays',
	];

	public function safeUp(): bool
	{
		$projectConfig = Craft::$app->getProjectConfig();
		$settingsPath = ProjectConfig::PATH_PLUGINS . '.' . Plugin::HANDLE . '.settings';
		$settings = $projectConfig->get($settingsPath);
		$settings = is_array($settings) ? $settings : [];

		$this->addColumn(Table::REMINDERS, 'days', (string) $this->integer()->notNull()->defaultValue(0)->after('type'));

		// Record each sent reminder's days, so the unique index blocks a resend
		foreach (self::LEGACY_KEYS as $type => $legacyKey) {
			$days = $settings[$legacyKey] ?? null;

			if (is_numeric($days)) {
				$this->update(Table::REMINDERS, [
					'days' => (int) $days,
				], [
					'type' => $type,
				]);
			}
		}

		$this->alterColumn(Table::REMINDERS, 'days', (string) $this->integer()->notNull());
		$this->dropIndexIfExists(Table::REMINDERS, ['type', 'invoiceId'], true);
		$this->dropIndexIfExists(Table::REMINDERS, ['type', 'orderId'], true);
		$this->createIndex(null, Table::REMINDERS, ['type', 'days', 'invoiceId'], true);
		$this->createIndex(null, Table::REMINDERS, ['type', 'days', 'orderId'], true);

		$schemaVersion = $projectConfig->get(ProjectConfig::PATH_PLUGINS . '.' . Plugin::HANDLE . '.schemaVersion', true);

		// Skip the conversion when another environment ran this migration first
		if (is_string($schemaVersion) && version_compare($schemaVersion, '1.1.0', '>=')) {
			return true;
		}

		$reminders = [];
		foreach (self::LEGACY_KEYS as $type => $legacyKey) {
			if (is_numeric($settings[$legacyKey] ?? null)) {
				$reminders[] = [
					'days' => (int) $settings[$legacyKey],
					'enabled' => true,
					'type' => $type,
				];
			}

			unset($settings[$legacyKey]);
		}

		// Pack the rows, as saving plugin settings does
		$settings['reminders'] = ProjectConfigHelper::packAssociativeArrays($reminders);

		// Drop settings 2.0.0 replaced: invoice emails are edited in Net Terms, and order reminders send Commerce emails
		unset($settings['invoiceEmailTemplate'], $settings['orderPath']);
		$projectConfig->set($settingsPath, $settings);

		return true;
	}
}
