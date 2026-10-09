<?php

declare(strict_types=1);

namespace fostercommerce\netterms\models;

use Craft;
use craft\base\Model;
use fostercommerce\netterms\enums\SublimitMode;
use fostercommerce\netterms\Plugin;

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
	 * Storefront path to one invoice, with `{number}` for the invoice number. Invoice emails and invoice reminders link here when set.
	 */
	public ?string $invoicePath = null;

	/**
	 * Site template for the invoice email. Null uses the plugin’s own.
	 */
	public ?string $invoiceEmailTemplate = null;

	/**
	 * Storefront path to one credit order, with `{number}` for the order number. Order reminders link here when set.
	 */
	public ?string $orderPath = null;

	/**
	 * Days before an invoice or order is due to send the due-soon reminder. Null turns the reminder off.
	 */
	public ?int $dueSoonReminderDays = null;

	/**
	 * Days after an invoice or order is due to send the overdue reminder. Null turns the reminder off.
	 */
	public ?int $overdueReminderDays = null;

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
	 * @return array<int, mixed>
	 */
	protected function defineRules(): array
	{
		return [
			[['defaultSublimitMode'],
				'in',
				'range' => array_map(static fn (SublimitMode $mode): string => $mode->value, SublimitMode::cases())],
			[['defaultSublimitMode'], 'validateDefaultSublimitMode'],
			[['defaultPaymentTerms', 'overdueReminderDays'],
				'integer',
				'min' => 0],
			[['dueSoonReminderDays'],
				'integer',
				'min' => 1],
		];
	}
}
