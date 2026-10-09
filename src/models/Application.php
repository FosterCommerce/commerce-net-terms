<?php

declare(strict_types=1);

namespace fostercommerce\netterms\models;

use craft\base\Model;
use DateTime;
use Money\Money;

/**
 * Part of a payment assigned to an invoice line or an order.
 */
class Application extends Model
{
	public ?int $id = null;

	public ?int $paymentId = null;

	public ?int $invoiceLineId = null;

	public ?int $orderId = null;

	/**
	 * The Commerce transaction recording this application on its order, in order billing.
	 */
	public ?int $transactionId = null;

	public Money $amount;

	public ?DateTime $dateReversed = null;

	public ?int $authorId = null;

	public ?DateTime $dateCreated = null;

	public function getIsActive(): bool
	{
		return ! $this->dateReversed instanceof DateTime;
	}
}
