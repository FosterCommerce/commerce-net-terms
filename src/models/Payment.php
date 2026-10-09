<?php

declare(strict_types=1);

namespace fostercommerce\netterms\models;

use Craft;
use craft\base\Model;
use DateTime;
use fostercommerce\netterms\enums\PaymentMethod;
use fostercommerce\netterms\helpers\Amounts;
use fostercommerce\netterms\Plugin;
use Money\Money;

/**
 * Money received against an account.
 */
class Payment extends Model
{
	public ?int $id = null;

	public ?int $accountId = null;

	public Money $amount;

	public PaymentMethod $method = PaymentMethod::Check;

	public ?string $reference = null;

	public ?DateTime $dateReceived = null;

	public ?string $note = null;

	public ?int $authorId = null;

	public ?DateTime $dateCreated = null;

	/**
	 * The amount as a localized number, for a money input. Blank for zero, which no payment can be.
	 */
	public function getAmountNumber(): ?string
	{
		return $this->amount->isZero() ? null : Amounts::toNumber($this->amount);
	}

	public function validateAmount(string $attribute): void
	{
		if (! $this->amount->isPositive()) {
			$this->addError($attribute, Craft::t(Plugin::HANDLE, 'payment.amountNotPositive'));
		}
	}

	/**
	 * @return array<int, mixed>
	 */
	protected function defineRules(): array
	{
		return [
			[['accountId', 'dateReceived'], 'required'],
			[['reference'],
				'string',
				'max' => 255],
			[['amount'], 'validateAmount'],
		];
	}
}
