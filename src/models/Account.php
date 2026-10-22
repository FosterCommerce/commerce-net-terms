<?php

declare(strict_types=1);

namespace fostercommerce\netterms\models;

use Craft;
use craft\base\Model;
use craft\commerce\models\Store;
use craft\commerce\Plugin as Commerce;
use craft\helpers\App;
use craft\helpers\UrlHelper;
use DateTime;
use fostercommerce\netterms\enums\AccountStatus;
use fostercommerce\netterms\enums\SublimitMode;
use fostercommerce\netterms\helpers\Amounts;
use fostercommerce\netterms\Plugin;
use Money\Currency;
use Money\Money;
use yii\base\InvalidConfigException;

/**
 * A store’s central holding for the terms billing on orders charged to it.
 */
class Account extends Model
{
	public ?int $id = null;

	public ?int $storeId = null;

	public ?string $title = null;

	public ?string $description = null;

	/**
	 * Null when the account has unlimited credit.
	 */
	public ?Money $creditLimit = null;

	public bool $unlimited = false;

	/**
	 * Null uses the plugin's default.
	 */
	public ?SublimitMode $sublimitMode = null;

	/**
	 * Days until an invoice is due. Null uses the plugin's default.
	 */
	public ?int $paymentTerms = null;

	/**
	 * Null sends account emails to the invoice’s buyers.
	 */
	public ?string $billingEmail = null;

	public AccountStatus $status = AccountStatus::Active;

	public ?DateTime $dateCreated = null;

	public ?DateTime $dateUpdated = null;

	/**
	 * @throws InvalidConfigException if the store doesn’t exist
	 */
	public function getStore(): Store
	{
		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();
		$store = $this->storeId !== null ? $commerce->getStores()->getStoreById($this->storeId) : null;

		if ($store === null) {
			throw new InvalidConfigException('An account needs a store.');
		}

		return $store;
	}

	/**
	 * @throws InvalidConfigException if the store has no currency
	 */
	public function getCurrency(): Currency
	{
		$currency = $this->getStore()->getCurrency();

		if (! $currency instanceof Currency) {
			throw new InvalidConfigException('The account’s store has no currency.');
		}

		return $currency;
	}

	public function getEffectiveSublimitMode(): SublimitMode
	{
		return $this->sublimitMode ?? Plugin::getInstance()->getSettings()->getDefaultSublimitMode();
	}

	public function getEffectivePaymentTerms(): int
	{
		return $this->paymentTerms ?? Plugin::getInstance()->getSettings()->defaultPaymentTerms;
	}

	/**
	 * The credit limit as a localized number, for a money input.
	 */
	public function getCreditLimitNumber(): ?string
	{
		return Amounts::toNumber($this->creditLimit);
	}

	public function getIsActive(): bool
	{
		return $this->status === AccountStatus::Active;
	}

	public function getCpEditUrl(): string
	{
		return UrlHelper::cpUrl('net-terms/accounts/' . $this->id);
	}

	/**
	 * @return Buyer[]
	 */
	public function getBuyers(): array
	{
		return $this->id !== null ? Plugin::getInstance()->getAccounts()->getBuyersByAccountId($this->id) : [];
	}

	public function validateCreditLimit(string $attribute): void
	{
		if ($this->creditLimit instanceof Money && $this->creditLimit->isNegative()) {
			$this->addError($attribute, Craft::t(Plugin::HANDLE, 'account.creditLimitNegative'));
		}
	}

	/**
	 * @return array<int, mixed>
	 */
	protected function defineRules(): array
	{
		return [
			[['title', 'description'], 'trim'],
			[['description'], 'default'],
			[['storeId', 'title'], 'required'],
			[['title'],
				'string',
				'max' => 255],
			[['creditLimit'],
				'required',
				'when' => fn (): bool => ! $this->unlimited],
			[['paymentTerms'],
				'integer',
				'min' => 0],
			[['billingEmail'], 'trim'],
			[['billingEmail'], 'default'],
			[['billingEmail'],
				'email',
				'enableIDN' => App::supportsIdn(),
				'enableLocalIDN' => App::supportsIdn()],
			[['billingEmail'],
				'string',
				'max' => 255],
			[['creditLimit'], 'validateCreditLimit'],
		];
	}
}
