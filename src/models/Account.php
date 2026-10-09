<?php

declare(strict_types=1);

namespace fostercommerce\netterms\models;

use Craft;
use craft\base\Model;
use craft\commerce\models\Store;
use craft\commerce\Plugin as Commerce;
use craft\elements\User;
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
 * A customer’s account in one store.
 */
class Account extends Model
{
	public ?int $id = null;

	public ?int $storeId = null;

	public ?int $holderId = null;

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

	public function getHolder(): ?User
	{
		return $this->holderId !== null ? Craft::$app->getUsers()->getUserById($this->holderId) : null;
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
			[['storeId', 'holderId'], 'required'],
			[['creditLimit'],
				'required',
				'when' => fn (): bool => ! $this->unlimited],
			[['paymentTerms'],
				'integer',
				'min' => 0],
			[['creditLimit'], 'validateCreditLimit'],
		];
	}
}
