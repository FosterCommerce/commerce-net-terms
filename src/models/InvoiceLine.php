<?php

declare(strict_types=1);

namespace fostercommerce\netterms\models;

use craft\base\Model;
use fostercommerce\netterms\Plugin;
use yii\base\InvalidConfigException;

/**
 * One buyer's part of an invoice. A null buyer bills the account's own adjustments.
 */
class InvoiceLine extends Model
{
	public ?int $id = null;

	public ?int $invoiceId = null;

	public ?int $buyerId = null;

	/**
	 * @throws InvalidConfigException if the invoice doesn’t exist
	 */
	public function getInvoice(): Invoice
	{
		$invoice = $this->invoiceId !== null ? Plugin::getInstance()->getInvoices()->getInvoiceById($this->invoiceId) : null;

		if (! $invoice instanceof Invoice) {
			throw new InvalidConfigException('An invoice line needs an invoice.');
		}

		return $invoice;
	}

	public function getBuyer(): ?Buyer
	{
		return $this->buyerId !== null ? Plugin::getInstance()->getAccounts()->getBuyerById($this->buyerId) : null;
	}
}
