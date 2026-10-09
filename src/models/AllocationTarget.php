<?php

declare(strict_types=1);

namespace fostercommerce\netterms\models;

use craft\base\Model;
use DateTime;
use fostercommerce\netterms\helpers\Amounts;
use Money\Money;

/**
 * What a payment can be applied to: an invoice line in invoice billing, or an order in order billing.
 */
class AllocationTarget extends Model
{
	/**
	 * The invoice line ID or order ID that allocations are keyed by.
	 */
	public int $id;

	/**
	 * The invoice number or order reference.
	 */
	public string $label;

	public string $url;

	public string $buyerName;

	public ?DateTime $dateDue = null;

	public bool $isOverdue = false;

	/**
	 * What is left to pay. Null for an application’s target, which only names it.
	 */
	public ?Money $balance = null;

	/**
	 * The balance as a plain decimal, for scripts that fill amount inputs.
	 */
	public function getBalanceDecimal(): string
	{
		return $this->balance instanceof Money ? Amounts::toDecimal($this->balance) : '0';
	}
}
