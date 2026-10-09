<?php

declare(strict_types=1);

namespace fostercommerce\netterms\models;

use Craft;
use craft\base\Model;
use craft\elements\User;
use DateTime;
use fostercommerce\netterms\helpers\Amounts;
use fostercommerce\netterms\Plugin;
use Money\Money;
use yii\base\InvalidConfigException;

/**
 * A user allowed to charge to an account.
 */
class Buyer extends Model
{
	public ?int $id = null;

	public ?int $accountId = null;

	public ?int $userId = null;

	/**
	 * Null means no sublimit.
	 */
	public ?Money $sublimit = null;

	/**
	 * An inactive buyer keeps their ledger history but can’t charge.
	 */
	public bool $active = true;

	public ?DateTime $dateCreated = null;

	public ?DateTime $dateUpdated = null;

	/**
	 * @var User|false|null false until the user is loaded
	 */
	private User|false|null $user = false;

	public function getUser(): ?User
	{
		if ($this->user === false) {
			$this->user = $this->userId !== null ? Craft::$app->getUsers()->getUserById($this->userId) : null;
		}

		return $this->user;
	}

	public function setUser(?User $user): void
	{
		$this->user = $user;
	}

	/**
	 * @throws InvalidConfigException if the account doesn’t exist
	 */
	public function getAccount(): Account
	{
		$account = $this->accountId !== null ? Plugin::getInstance()->getAccounts()->getAccountById($this->accountId) : null;

		if (! $account instanceof Account) {
			throw new InvalidConfigException('A buyer needs an account.');
		}

		return $account;
	}

	/**
	 * The sublimit as a localized number, for a money input.
	 */
	public function getSublimitNumber(): ?string
	{
		return Amounts::toNumber($this->sublimit);
	}

	public function getName(): string
	{
		$user = $this->getUser();

		if (! $user instanceof User) {
			return Craft::t(Plugin::HANDLE, 'buyer.deletedUser');
		}

		$fullName = $user->getFullName();

		return $fullName !== null && $fullName !== '' ? $fullName : (string) $user->email;
	}

	public function validateSublimit(string $attribute): void
	{
		if ($this->sublimit instanceof Money && $this->sublimit->isNegative()) {
			$this->addError($attribute, Craft::t(Plugin::HANDLE, 'buyer.sublimitNegative'));
		}
	}

	/**
	 * @return array<int, mixed>
	 */
	protected function defineRules(): array
	{
		return [
			[['accountId', 'userId'], 'required'],
			[['sublimit'], 'validateSublimit'],
		];
	}
}
