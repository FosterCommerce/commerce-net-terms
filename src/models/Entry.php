<?php

declare(strict_types=1);

namespace fostercommerce\netterms\models;

use craft\base\Model;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use DateTime;
use fostercommerce\netterms\enums\EntryType;
use Money\Money;

/**
 * A ledger entry. A positive amount raises what the account owes.
 */
class Entry extends Model
{
	public ?int $id = null;

	public ?int $accountId = null;

	public ?int $buyerId = null;

	public EntryType $type = EntryType::Adjustment;

	public Money $amount;

	public ?int $orderId = null;

	public ?string $transactionHash = null;

	public ?int $invoiceLineId = null;

	public ?int $applicationId = null;

	public ?string $note = null;

	public ?int $authorId = null;

	public ?DateTime $dateCreated = null;

	public function getOrder(): ?Order
	{
		if ($this->orderId === null) {
			return null;
		}

		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();

		return $commerce->getOrders()->getOrderById($this->orderId);
	}
}
