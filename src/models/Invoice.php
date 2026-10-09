<?php

declare(strict_types=1);

namespace fostercommerce\netterms\models;

use craft\base\Model;
use craft\helpers\UrlHelper;
use DateTime;
use DateTimeZone;
use fostercommerce\netterms\Plugin;
use yii\base\InvalidConfigException;

/**
 * A bill for an account's charges, one line per buyer.
 */
class Invoice extends Model
{
	public ?int $id = null;

	public ?int $accountId = null;

	public ?string $number = null;

	public ?DateTime $dateIssued = null;

	public ?DateTime $dateDue = null;

	public ?DateTime $dateVoided = null;

	/**
	 * @throws InvalidConfigException if the account doesn’t exist
	 */
	public function getAccount(): Account
	{
		$account = $this->accountId !== null ? Plugin::getInstance()->getAccounts()->getAccountById($this->accountId) : null;

		if (! $account instanceof Account) {
			throw new InvalidConfigException('An invoice needs an account.');
		}

		return $account;
	}

	/**
	 * @return InvoiceLine[]
	 */
	public function getLines(): array
	{
		return $this->id !== null ? Plugin::getInstance()->getInvoices()->getLinesByInvoiceId($this->id) : [];
	}

	public function getIsVoided(): bool
	{
		return $this->dateVoided instanceof DateTime;
	}

	/**
	 * Days from the issue date to the due date.
	 */
	public function getPaymentTerms(): int
	{
		if (! $this->dateIssued instanceof DateTime || ! $this->dateDue instanceof DateTime) {
			return 0;
		}

		// Count in UTC, since due dates are worked out in UTC
		$utc = new DateTimeZone('UTC');

		return (int) (clone $this->dateIssued)->setTimezone($utc)->diff((clone $this->dateDue)->setTimezone($utc))->days;
	}

	public function getIsOverdue(): bool
	{
		return ! $this->getIsVoided()
			&& $this->dateDue instanceof DateTime
			&& $this->dateDue < new DateTime()
			&& Plugin::getInstance()->getInvoices()->getBalance($this)->isPositive();
	}

	public function getCpEditUrl(): string
	{
		return UrlHelper::cpUrl('net-terms/invoices/' . $this->id);
	}
}
