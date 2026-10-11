<?php

declare(strict_types=1);

namespace fostercommerce\netterms\models;

use Craft;
use craft\base\Model;
use craft\commerce\Plugin as Commerce;
use fostercommerce\netterms\enums\Billing;
use fostercommerce\netterms\enums\ReminderType;
use fostercommerce\netterms\enums\SublimitMode;
use fostercommerce\netterms\Plugin;
use fostercommerce\netterms\records\InvoiceRecord;

class Settings extends Model
{
	/**
	 * How sublimits behave on an account that doesn’t choose: `ceiling` or `reserved`.
	 */
	public string $defaultSublimitMode = 'ceiling';

	/**
	 * Days from an invoice’s issue date, or an order’s date in order billing, to its due date, on an account that doesn’t choose.
	 */
	public int $defaultPaymentTerms = 30;

	/**
	 * Text before each invoice number. Blank uses `INV-`.
	 */
	public string $invoiceNumberPrefix = '';

	/**
	 * The number the first invoice uses, padded to six digits.
	 */
	public int $invoiceNumberStart = 1;

	/**
	 * Storefront path to one invoice, with `{number}` for the invoice number. Invoice emails and invoice reminders link here when set.
	 */
	public ?string $invoicePath = null;

	/**
	 * Order billing's reminders, each a number of days before (`dueSoon`) or after (`overdue`) an order is due, and the Commerce email it sends by `emailUid`. Invoice billing's reminders are emails in Net Terms -> Emails.
	 *
	 * @var array<int, array{days: int|null, emailUid?: string|null, enabled: bool, type: string}>
	 */
	public array $reminders = [];

	/**
	 * Site template for the invoice PDF. Null uses the plugin's own.
	 */
	public ?string $invoicePdfTemplate = null;

	/**
	 * Paper size for the invoice PDF: `letter`, `legal` or `a4`.
	 */
	public string $invoicePdfPaperSize = 'letter';

	/**
	 * Orientation for the invoice PDF: `portrait` or `landscape`.
	 */
	public string $invoicePdfOrientation = 'portrait';

	public function getInvoiceNumberPrefix(): string
	{
		return $this->invoiceNumberPrefix !== '' ? $this->invoiceNumberPrefix : 'INV-';
	}

	public function getDefaultSublimitMode(): SublimitMode
	{
		return SublimitMode::tryFrom($this->defaultSublimitMode) ?? SublimitMode::Ceiling;
	}

	/**
	 * Refuse Reserved as the default while an account on the default has sublimits that add up to more than its limit.
	 */
	public function validateDefaultSublimitMode(string $attribute): void
	{
		if ($this->getDefaultSublimitMode() !== SublimitMode::Reserved) {
			return;
		}

		$holderNames = array_map(
			static fn (Account $account): string => (string) $account->getHolder()?->getName(),
			Plugin::getInstance()->getAccounts()->getDefaultModeAccountsOverReserved(),
		);

		if ($holderNames !== []) {
			$this->addError($attribute, Craft::t(Plugin::HANDLE, 'settings.defaultReservedOverLimit', [
				'accounts' => implode(', ', $holderNames),
			]));
		}
	}

	/**
	 * Refuse a new first number once an invoice exists, since a lower one would reuse issued numbers.
	 */
	public function validateInvoiceNumberStart(string $attribute): void
	{
		// Compare with the start numbering uses, which `config/net-terms.php` overrides
		$fileConfig = Craft::$app->getConfig()->getConfigFromFile(Plugin::HANDLE);
		$savedStart = (is_array($fileConfig) ? $fileConfig['invoiceNumberStart'] ?? null : null)
			?? Craft::$app->getProjectConfig()->get('plugins.' . Plugin::HANDLE . '.settings.invoiceNumberStart');

		if ((is_numeric($savedStart) ? (int) $savedStart : 1) !== $this->invoiceNumberStart && InvoiceRecord::find()->exists()) {
			$this->addError($attribute, Craft::t(Plugin::HANDLE, 'settings.invoiceNumberStartLocked'));
		}
	}

	public function validateReminders(string $attribute): void
	{
		$emailUids = [];
		$isOrderBilling = Plugin::getInstance()->getBilling() === Billing::Orders;
		if ($isOrderBilling) {
			/** @var Commerce $commerce */
			$commerce = Commerce::getInstance();
			$emailUids = $commerce->getEmails()->getAllEnabledEmails()->pluck('uid')->all();
		}

		$seenReminders = [];
		foreach ($this->reminders as $reminder) {
			$minimumDays = $reminder['type'] === ReminderType::DueSoon->value ? 1 : 0;

			if (! ReminderType::tryFrom($reminder['type']) instanceof ReminderType || $reminder['days'] === null || $reminder['days'] < $minimumDays) {
				$this->addError($attribute, Craft::t(Plugin::HANDLE, 'settings.reminderInvalid'));

				return;
			}

			// Refuse a repeated row, since the reminders table records one send per type and days
			$reminderKey = $reminder['type'] . ':' . $reminder['days'];
			if (isset($seenReminders[$reminderKey])) {
				$this->addError($attribute, Craft::t(Plugin::HANDLE, 'settings.reminderDuplicate'));

				return;
			}

			$seenReminders[$reminderKey] = true;

			if ($isOrderBilling && $reminder['enabled'] && ! in_array($reminder['emailUid'] ?? null, $emailUids, true)) {
				$this->addError($attribute, Craft::t(Plugin::HANDLE, 'settings.reminderEmailRequired'));

				return;
			}
		}
	}

	/**
	 * @return array<int, mixed>
	 */
	protected function defineRules(): array
	{
		return [
			// Read the settings lightswitch's blank off value as Ceiling
			[['defaultSublimitMode'],
				'filter',
				'filter' => static fn (mixed $value): mixed => $value === '' ? SublimitMode::Ceiling->value : $value],
			[['defaultSublimitMode'],
				'in',
				'range' => array_map(static fn (SublimitMode $mode): string => $mode->value, SublimitMode::cases())],
			[['defaultSublimitMode'], 'validateDefaultSublimitMode'],
			[['defaultPaymentTerms'],
				'integer',
				'min' => 0],
			[['reminders'],
				'filter',
				'filter' => $this->normalizeReminders(...)],
			[['reminders'], 'validateReminders'],
			[['invoicePdfPaperSize'],
				'in',
				'range' => ['letter', 'legal', 'a4']],
			[['invoicePdfOrientation'],
				'in',
				'range' => ['portrait', 'landscape']],
			// Leave room in the 32-character number column for the digits
			[['invoiceNumberPrefix'],
				'string',
				'max' => 20],
			[['invoiceNumberStart'],
				'integer',
				'min' => 1],
			[['invoiceNumberStart'], 'validateInvoiceNumberStart'],
		];
	}

	/**
	 * Cast the editable table's posted rows, keyed by row ID, to typed rows.
	 *
	 * @return array<int, array{days: int|null, emailUid: string|null, enabled: bool, type: string}>
	 */
	private function normalizeReminders(mixed $rows): array
	{
		$reminders = [];

		foreach (is_array($rows) ? $rows : [] as $row) {
			if (! is_array($row)) {
				continue;
			}

			$reminders[] = [
				'days' => is_numeric($row['days'] ?? null) ? (int) $row['days'] : null,
				'emailUid' => is_string($row['emailUid'] ?? null) && $row['emailUid'] !== '' ? $row['emailUid'] : null,
				'enabled' => (bool) ($row['enabled'] ?? false),
				'type' => is_string($row['type'] ?? null) ? $row['type'] : '',
			];
		}

		return $reminders;
	}
}
