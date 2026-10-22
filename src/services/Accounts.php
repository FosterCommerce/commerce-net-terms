<?php

declare(strict_types=1);

namespace fostercommerce\netterms\services;

use Craft;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use fostercommerce\netterms\db\Table;
use fostercommerce\netterms\enums\AccountStatus;
use fostercommerce\netterms\enums\Billing;
use fostercommerce\netterms\enums\SublimitMode;
use fostercommerce\netterms\errors\LedgerException;
use fostercommerce\netterms\events\DefineAccountAccessEvent;
use fostercommerce\netterms\events\DefineBuyerEligibilityEvent;
use fostercommerce\netterms\events\ResolveBuyerEvent;
use fostercommerce\netterms\helpers\Amounts;
use fostercommerce\netterms\models\Account;
use fostercommerce\netterms\models\Buyer;
use fostercommerce\netterms\Plugin;
use fostercommerce\netterms\records\AccountRecord;
use fostercommerce\netterms\records\BuyerRecord;
use Money\Currency;
use Money\Money;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Accounts, their buyers, and the credit each buyer has available.
 */
class Accounts extends Component
{
	/**
	 * The event that is triggered when resolving which user is charging an order.
	 *
	 * ```php
	 * use fostercommerce\netterms\events\ResolveBuyerEvent;
	 * use fostercommerce\netterms\services\Accounts;
	 * use yii\base\Event;
	 *
	 * Event::on(Accounts::class, Accounts::EVENT_RESOLVE_BUYER, function(ResolveBuyerEvent $event) {
	 *     // Refuse Net Terms for guests and personal orders
	 *     if ($event->userId !== null) {
	 *         $event->userId = MyCompanies::companyForOrder($event->order)?->accountUserId;
	 *     }
	 * });
	 * ```
	 */
	public const EVENT_RESOLVE_BUYER = 'resolveBuyer';

	/**
	 * The event that is triggered when deciding whether a user can see or manage an account on the storefront.
	 *
	 * ```php
	 * use fostercommerce\netterms\events\DefineAccountAccessEvent;
	 * use fostercommerce\netterms\services\Accounts;
	 * use yii\base\Event;
	 *
	 * Event::on(Accounts::class, Accounts::EVENT_DEFINE_ACCOUNT_ACCESS, function(DefineAccountAccessEvent $event) {
	 *     // Let the company’s admins manage its account
	 *     $event->canManage = MyCompanies::isAdmin($event->user, $event->account->id);
	 *     // Let other company members see it without buying on it
	 *     $event->canView = MyCompanies::isMember($event->user, $event->account->id);
	 * });
	 * ```
	 */
	public const EVENT_DEFINE_ACCOUNT_ACCESS = 'defineAccountAccess';

	/**
	 * The event that is triggered when the storefront adds a buyer, to decide whether the user can be one.
	 *
	 * ```php
	 * use fostercommerce\netterms\events\DefineBuyerEligibilityEvent;
	 * use fostercommerce\netterms\services\Accounts;
	 * use yii\base\Event;
	 *
	 * Event::on(Accounts::class, Accounts::EVENT_DEFINE_BUYER_ELIGIBILITY, function(DefineBuyerEligibilityEvent $event) {
	 *     // Only the company's own people can buy on its account
	 *     $event->isEligible = MyCompanies::isMember($event->user, $event->account->id);
	 * });
	 * ```
	 */
	public const EVENT_DEFINE_BUYER_ELIGIBILITY = 'defineBuyerEligibility';

	/**
	 * @var array<int, Account|null>
	 */
	private array $accountsById = [];

	public function getAccountById(int $id): ?Account
	{
		if (! array_key_exists($id, $this->accountsById)) {
			$record = AccountRecord::findOne($id);
			$this->accountsById[$id] = $record instanceof AccountRecord ? $this->createAccountFromRecord($record) : null;
		}

		return $this->accountsById[$id];
	}

	/**
	 * Read an account from the database rather than from this request’s earlier reads.
	 */
	public function getFreshAccountById(int $id): ?Account
	{
		unset($this->accountsById[$id]);

		return $this->getAccountById($id);
	}

	/**
	 * @throws InvalidConfigException if the account doesn’t exist
	 */
	public function getCurrencyByAccountId(int $accountId): Currency
	{
		$account = $this->getAccountById($accountId);

		if (! $account instanceof Account) {
			throw new InvalidConfigException(sprintf('Account %d doesn’t exist.', $accountId));
		}

		return $account->getCurrency();
	}

	/**
	 * @return Account[]
	 */
	public function getAllAccounts(): array
	{
		/** @var AccountRecord[] $records */
		$records = AccountRecord::find()->orderBy([
			'title' => SORT_ASC,
			'id' => SORT_ASC,
		])->all();

		return array_map($this->createAccountFromRecord(...), $records);
	}

	/**
	 * Accounts that use the default sublimit mode and whose active sublimits add up to more than their credit limit, which Reserved mode refuses.
	 *
	 * @return Account[]
	 */
	public function getDefaultModeAccountsOverReserved(): array
	{
		return array_values(array_filter(
			$this->getAllAccounts(),
			fn (Account $account): bool => ! $account->sublimitMode instanceof SublimitMode
				&& ! $this->reservedTotalFits($account, $this->getBuyersByAccountId((int) $account->id)),
		));
	}

	/**
	 * Each buyer’s balance, keyed by buyer ID: from the ledger in invoice billing, from their credit orders’ balances in order billing.
	 *
	 * @return array<int, Money>
	 */
	public function getBalanceByBuyers(Account $account): array
	{
		$plugin = Plugin::getInstance();

		return $plugin->getBilling() === Billing::Orders
			? $plugin->getCreditOrders()->getBalanceByBuyerId($account)
			: $plugin->getLedger()->getBalanceByBuyers($account);
	}

	public function getBalanceByAccount(Account $account): Money
	{
		return Money::sum(Amounts::zero($account->getCurrency()), ...array_values($this->getBalanceByBuyers($account)));
	}

	public function getBalanceByBuyer(Buyer $buyer): Money
	{
		$account = $buyer->getAccount();

		return $this->getBalanceByBuyers($account)[(int) $buyer->id] ?? Amounts::zero($account->getCurrency());
	}

	/**
	 * Accounts with a balance, unapplied credit, an unpaid invoice or credit orders in the trash. An unpaid invoice counts even when the account’s balance is $0, since order billing has no page for it.
	 *
	 * @return Account[]
	 */
	public function getUnsettledAccounts(): array
	{
		$plugin = Plugin::getInstance();

		return array_values(array_filter(
			$this->getAllAccounts(),
			fn (Account $account): bool => ! $this->getBalanceByAccount($account)->isZero()
				|| ! $plugin->getPayments()->getUnappliedByAccount($account)->isZero()
				|| $plugin->getInvoices()->getOpenLinesByAccountId((int) $account->id) !== []
				|| $this->hasTrashedOrders($account),
		));
	}

	/**
	 * Whether the account has credit orders in the trash. Its balance leaves them out until they are restored.
	 */
	public function hasTrashedOrders(Account $account): bool
	{
		$trashedOrderIds = (new Query())
			->select(['id'])
			->from(CraftTable::ELEMENTS)
			->where([
				'not',
				[
					'dateDeleted' => null,
				],
			]);
		if ((new Query())
			->from(Table::ENTRIES)
			->where([
				'accountId' => $account->id,
				'orderId' => $trashedOrderIds,
			])
			->exists()) {
			return true;
		}

		return (new Query())
			->from(Table::ORDERS)
			->where([
				'accountId' => $account->id,
				'orderId' => $trashedOrderIds,
			])
			->exists();
	}

	/**
	 * The accounts a user is an active buyer on, across stores, by title.
	 *
	 * @return Account[]
	 */
	public function getAccountsByBuyerUserId(int $userId): array
	{
		/** @var AccountRecord[] $records */
		$records = AccountRecord::find()
			->where([
				'id' => (new Query())
					->select(['accountId'])
					->from(Table::BUYERS)
					->where([
						'userId' => $userId,
						'active' => true,
					]),
			])
			->orderBy([
				'title' => SORT_ASC,
				'id' => SORT_ASC,
			])
			->all();

		return array_map($this->createAccountFromRecord(...), $records);
	}

	public function getBuyerById(int $id): ?Buyer
	{
		$record = BuyerRecord::findOne($id);

		return $record instanceof BuyerRecord ? $this->createBuyerFromRecord($record) : null;
	}

	public function getBuyer(Account $account, int $userId): ?Buyer
	{
		$record = BuyerRecord::findOne([
			'accountId' => $account->id,
			'userId' => $userId,
		]);

		return $record instanceof BuyerRecord ? $this->createBuyerFromRecord($record) : null;
	}

	/**
	 * @return Buyer[]
	 */
	public function getBuyersByAccountId(int $accountId): array
	{
		/** @var BuyerRecord[] $records */
		$records = BuyerRecord::find()
			->where([
				'accountId' => $accountId,
			])
			->orderBy([
				'id' => SORT_ASC,
			])
			->all();

		return array_map($this->createBuyerFromRecord(...), $records);
	}

	/**
	 * The paying user’s active buyers on accounts in the order’s store, by account title.
	 *
	 * @return Buyer[]
	 */
	public function getBuyersForOrder(Order $order): array
	{
		// Charge a storefront order only to the signed-in customer, since a guest can enter any customer's email
		$customerId = $order->getCustomerId();
		$isCustomerPaying = ! Craft::$app->getRequest()->getIsSiteRequest() || Craft::$app->getUser()->getId() === $customerId;

		$event = new ResolveBuyerEvent([
			'order' => $order,
			'userId' => $isCustomerPaying ? $customerId : null,
		]);
		$this->trigger(self::EVENT_RESOLVE_BUYER, $event);

		if ($event->userId === null) {
			return [];
		}

		/** @var BuyerRecord[] $records */
		$records = BuyerRecord::find()
			->alias('buyers')
			->innerJoin([
				'accounts' => Table::ACCOUNTS,
			], '[[accounts.id]] = [[buyers.accountId]]')
			->where([
				'buyers.userId' => $event->userId,
				'buyers.active' => true,
				'accounts.storeId' => $order->getStore()->id,
			])
			->orderBy([
				'accounts.title' => SORT_ASC,
				'accounts.id' => SORT_ASC,
			])
			->all();

		return array_map($this->createBuyerFromRecord(...), $records);
	}

	/**
	 * The account’s balance, the credit left before any sublimit applies, and how far it is over its credit limit. Room is null for unlimited credit, and over limit is null when the account isn’t over.
	 *
	 * @return array{balance: Money, room: Money|null, overLimit: Money|null}
	 */
	public function getAccountFigures(Account $account): array
	{
		$balance = $this->getBalanceByAccount($account);
		$overLimit = $account->creditLimit instanceof Money ? $balance->subtract($account->creditLimit) : null;

		return [
			'balance' => $balance,
			'room' => $this->calculateAccountRoom($account, $balance),
			'overLimit' => $overLimit instanceof Money && $overLimit->isPositive() ? $overLimit : null,
		];
	}

	/**
	 * How far the account’s balance is over its credit limit, or null when it isn’t.
	 */
	public function getOverLimit(Account $account): ?Money
	{
		return $this->getAccountFigures($account)['overLimit'];
	}

	/**
	 * How far each buyer’s balance is over their sublimit, keyed by buyer ID, for buyers who are over.
	 *
	 * @param array<int, Money>|null $balanceByBuyerId each buyer’s balance, when the caller has it
	 * @return array<int, Money>
	 */
	public function getOverSublimitByBuyerId(Account $account, ?array $balanceByBuyerId = null): array
	{
		$balanceByBuyerId ??= $this->getBalanceByBuyers($account);
		$overByBuyerId = [];
		$zero = Amounts::zero($account->getCurrency());

		foreach ($this->getBuyersByAccountId((int) $account->id) as $buyer) {
			if (! $buyer->sublimit instanceof Money) {
				continue;
			}

			$over = ($balanceByBuyerId[(int) $buyer->id] ?? $zero)->subtract($buyer->sublimit);
			if ($over->isPositive()) {
				$overByBuyerId[(int) $buyer->id] = $over;
			}
		}

		return $overByBuyerId;
	}

	/**
	 * The most the buyer can charge right now. Null when it is unlimited.
	 */
	public function getAvailableCredit(Buyer $buyer): ?Money
	{
		$availableByBuyerId = $this->getAvailableCreditByBuyerId($buyer->getAccount());

		return $availableByBuyerId[(int) $buyer->id];
	}

	/**
	 * Whether the buyer’s available credit covers the amount.
	 */
	public function canCharge(Buyer $buyer, Money $amount): bool
	{
		$available = $this->getAvailableCredit($buyer);

		return ! $available instanceof Money || $available->greaterThanOrEqual($amount);
	}

	/**
	 * The most each of an account's buyers can charge right now, keyed by buyer ID. Null when it is unlimited.
	 *
	 * @param array<int, Money>|null $balanceByBuyerId each buyer’s balance, when the caller has it
	 * @return array<int, Money|null>
	 */
	public function getAvailableCreditByBuyerId(Account $account, ?array $balanceByBuyerId = null): array
	{
		$zero = Amounts::zero($account->getCurrency());
		$buyers = $this->getBuyersByAccountId((int) $account->id);
		$balanceByBuyerId ??= $this->getBalanceByBuyers($account);
		$accountBalance = Money::sum($zero, ...array_values($balanceByBuyerId));
		$accountRoom = $this->calculateAccountRoom($account, $accountBalance);
		$unreservedRoom = $account->creditLimit instanceof Money
			? Money::max($zero, $this->calculateUnreservedRoom($buyers, $balanceByBuyerId, $account->creditLimit, $accountBalance))
			: null;
		$isCeiling = $account->getEffectiveSublimitMode() === SublimitMode::Ceiling;

		$availableByBuyerId = [];
		foreach ($buyers as $buyer) {
			$buyerBalance = $balanceByBuyerId[(int) $buyer->id] ?? $zero;
			$availableByBuyerId[(int) $buyer->id] = match (true) {
				! $buyer->active => $zero,
				$buyer->sublimit instanceof Money => $this->minOf($accountRoom, Money::max($zero, $buyer->sublimit->subtract($buyerBalance))),
				$isCeiling => $accountRoom,
				default => $this->minOf($accountRoom, $unreservedRoom),
			};
		}

		return $availableByBuyerId;
	}

	/**
	 * Whether the user has ledger history as a buyer, which keeps the user from being deleted.
	 */
	public function hasLedgerHistory(int $userId): bool
	{
		$buyerIds = array_map(intval(...), (new Query())
			->select(['id'])
			->from(Table::BUYERS)
			->where([
				'userId' => $userId,
			])
			->column());
		return $this->hasBuyerHistory($buyerIds);
	}

	/**
	 * Save an account, enforcing the sublimit rules for its mode.
	 *
	 * @throws LedgerException if the account is locked by another request
	 */
	public function saveAccount(Account $account): bool
	{
		if (! $account->validate()) {
			return false;
		}

		if ($account->id === null) {
			$record = new AccountRecord();
			$record->storeId = (int) $account->storeId;
			$this->populateAccountRecord($record, $account);
			$record->save(false);
			$account->id = $record->id;

			return true;
		}

		return Plugin::getInstance()->getLedger()->withAccountLock($account->id, function () use ($account): bool {
			if (! $this->validateReservedTotal($account, $this->getBuyersByAccountId((int) $account->id))) {
				$account->addError('creditLimit', Craft::t(Plugin::HANDLE, 'account.reservedOverLimit'));

				return false;
			}

			/** @var AccountRecord $record */
			$record = AccountRecord::findOne($account->id);
			$this->populateAccountRecord($record, $account);
			$record->save(false);
			unset($this->accountsById[(int) $account->id]);

			return true;
		});
	}

	/**
	 * Save a buyer, enforcing that a sublimit covers their balance and fits the account’s mode.
	 *
	 * @throws LedgerException if the account is locked by another request
	 */
	public function saveBuyer(Buyer $buyer): bool
	{
		if (! $buyer->validate()) {
			return false;
		}

		return Plugin::getInstance()->getLedger()->withAccountLock((int) $buyer->accountId, function () use ($buyer): bool {
			// Re-read the account inside the lock, since staff can change its mode or limit while this waits
			/** @var Account $account */
			$account = $this->getFreshAccountById((int) $buyer->accountId);
			$existing = $this->getBuyer($account, (int) $buyer->userId);

			// Reactivate the row of a buyer removed with ledger history
			if ($existing instanceof Buyer && ! $existing->active && $buyer->id === null) {
				$buyer->id = $existing->id;
			} elseif ($existing instanceof Buyer && $existing->id !== $buyer->id) {
				$buyer->addError('userId', Craft::t(Plugin::HANDLE, 'buyer.alreadyOnAccount'));

				return false;
			}

			// Refuse only a lowered sublimit below the buyer’s balance, so a buyer already over can still be saved or reactivated
			$savedSublimit = $buyer->id !== null ? $this->getBuyerById($buyer->id)?->sublimit : null;
			$isLowered = $buyer->sublimit instanceof Money && (! $savedSublimit instanceof Money || $buyer->sublimit->lessThan($savedSublimit));
			if ($buyer->id !== null && $buyer->sublimit instanceof Money && $isLowered) {
				$balance = $this->getBalanceByBuyer($buyer);
				if ($buyer->sublimit->lessThan($balance)) {
					$buyer->addError('sublimit', Craft::t(Plugin::HANDLE, 'buyer.sublimitBelowBalance', [
						'balance' => Amounts::toString($balance),
					]));

					return false;
				}
			}

			$otherBuyers = array_filter(
				$this->getBuyersByAccountId((int) $account->id),
				static fn (Buyer $otherBuyer): bool => $otherBuyer->id !== $buyer->id,
			);

			if (! $this->validateReservedTotal($account, [...$otherBuyers, $buyer])) {
				$buyer->addError('sublimit', Craft::t(Plugin::HANDLE, 'buyer.reservedOverLimit'));

				return false;
			}

			$record = $buyer->id !== null ? BuyerRecord::findOne($buyer->id) : new BuyerRecord();
			/** @var BuyerRecord $record */
			$record->accountId = (int) $buyer->accountId;
			$record->userId = (int) $buyer->userId;
			$record->sublimit = $buyer->sublimit instanceof Money ? Amounts::toDecimal($buyer->sublimit) : null;
			$record->active = $buyer->active;
			$record->save(false);
			$buyer->id = $record->id;

			return true;
		});
	}

	/**
	 * Remove a buyer, or deactivate them when the ledger has entries for them.
	 *
	 * @throws LedgerException if the account is locked by another request
	 */
	public function removeBuyer(Buyer $buyer): void
	{
		Plugin::getInstance()->getLedger()->withAccountLock((int) $buyer->accountId, function () use ($buyer): void {
			$hasHistory = $this->hasBuyerHistory([(int) $buyer->id]);

			if (! $hasHistory) {
				BuyerRecord::deleteAll([
					'id' => $buyer->id,
				]);

				return;
			}

			$buyer->active = false;
			Db::update(Table::BUYERS, [
				'active' => false,
			], [
				'id' => $buyer->id,
			]);
		});
	}

	/**
	 * Delete an account with no ledger entries, invoices or payments.
	 *
	 * @throws LedgerException if the account is locked by another request
	 */
	public function deleteAccount(Account $account): bool
	{
		return Plugin::getInstance()->getLedger()->withAccountLock((int) $account->id, function () use ($account): bool {
			foreach ([Table::ENTRIES, Table::ORDERS, Table::INVOICES, Table::PAYMENTS] as $table) {
				$hasRows = (new Query())
					->from($table)
					->where([
						'accountId' => $account->id,
					])
					->exists();

				if ($hasRows) {
					return false;
				}
			}

			AccountRecord::deleteAll([
				'id' => $account->id,
			]);
			unset($this->accountsById[(int) $account->id]);

			return true;
		});
	}

	public function canManage(Account $account, User $user): bool
	{
		return $this->defineAccess($account, $user)->canManage;
	}

	/**
	 * Whether the user can see the account on the storefront.
	 */
	public function canView(Account $account, User $user): bool
	{
		$event = $this->defineAccess($account, $user);

		return $event->canView || $event->canManage;
	}

	/**
	 * Whether the storefront can add the user as a buyer on the account.
	 */
	public function canBeBuyer(Account $account, User $user): bool
	{
		$event = new DefineBuyerEligibilityEvent([
			'account' => $account,
			'user' => $user,
		]);
		$this->trigger(self::EVENT_DEFINE_BUYER_ELIGIBILITY, $event);

		return $event->isEligible;
	}

	private function createAccountFromRecord(AccountRecord $record): Account
	{
		$account = new Account([
			'id' => $record->id,
			'storeId' => $record->storeId,
			'title' => $record->title,
			'description' => $record->description,
			'sublimitMode' => $record->sublimitMode !== null ? SublimitMode::from($record->sublimitMode) : null,
			'paymentTerms' => $record->paymentTerms,
			'billingEmail' => $record->billingEmail,
			'status' => AccountStatus::from($record->status),
			'dateCreated' => DateTimeHelper::toDateTime($record->dateCreated) ?: null,
			'dateUpdated' => DateTimeHelper::toDateTime($record->dateUpdated) ?: null,
		]);
		$account->creditLimit = $record->creditLimit !== null ? Amounts::toMoney($record->creditLimit, $account->getCurrency()) : null;
		$account->unlimited = $record->creditLimit === null;

		return $account;
	}

	private function defineAccess(Account $account, User $user): DefineAccountAccessEvent
	{
		$event = new DefineAccountAccessEvent([
			'account' => $account,
			'user' => $user,
			'canView' => $this->getBuyer($account, (int) $user->id)?->active === true,
		]);
		$this->trigger(self::EVENT_DEFINE_ACCOUNT_ACCESS, $event);

		return $event;
	}

	private function createBuyerFromRecord(BuyerRecord $record): Buyer
	{
		return new Buyer([
			'id' => $record->id,
			'accountId' => $record->accountId,
			'userId' => $record->userId,
			'sublimit' => $record->sublimit !== null ? Amounts::toMoney($record->sublimit, $this->getCurrencyByAccountId($record->accountId)) : null,
			'active' => (bool) $record->active,
			'dateCreated' => DateTimeHelper::toDateTime($record->dateCreated) ?: null,
			'dateUpdated' => DateTimeHelper::toDateTime($record->dateUpdated) ?: null,
		]);
	}

	/**
	 * Copy the editable attributes. The store is set once, on insert.
	 */
	private function populateAccountRecord(AccountRecord $record, Account $account): void
	{
		$record->creditLimit = $account->unlimited || ! $account->creditLimit instanceof Money ? null : Amounts::toDecimal($account->creditLimit);
		$record->sublimitMode = $account->sublimitMode?->value;
		$record->paymentTerms = $account->paymentTerms;
		$record->title = (string) $account->title;
		$record->description = $account->description;
		$record->billingEmail = $account->billingEmail;
		$record->status = $account->status->value;
	}

	/**
	 * Credit left on the account. Null when the account has unlimited credit.
	 */
	private function calculateAccountRoom(Account $account, Money $accountBalance): ?Money
	{
		$zero = Amounts::zero($account->getCurrency());

		if (! $account->getIsActive()) {
			return $zero;
		}

		return $account->creditLimit instanceof Money ? Money::max($zero, $account->creditLimit->subtract($accountBalance)) : null;
	}

	/**
	 * The smaller amount, where null is unlimited.
	 */
	private function minOf(?Money $first, ?Money $second): ?Money
	{
		if (! $first instanceof Money) {
			return $second;
		}

		return $second instanceof Money ? Money::min($first, $second) : $first;
	}

	/**
	 * Credit left for buyers without a sublimit once every reserved amount is set aside.
	 *
	 * @param Buyer[] $buyers
	 * @param array<int, Money> $balanceByBuyerId
	 */
	private function calculateUnreservedRoom(array $buyers, array $balanceByBuyerId, Money $creditLimit, Money $accountBalance): Money
	{
		$zero = Amounts::zero($creditLimit->getCurrency());
		$room = $creditLimit->subtract($accountBalance);

		// Set aside each reserving buyer's unspent sublimit. Their balances are already in the account total.
		foreach ($buyers as $buyer) {
			if (! $buyer->active) {
				continue;
			}

			if (! $buyer->sublimit instanceof Money) {
				continue;
			}

			$buyerBalance = $balanceByBuyerId[(int) $buyer->id] ?? $zero;
			$room = $room->subtract(Money::max($zero, $buyer->sublimit->subtract($buyerBalance)));
		}

		return $room;
	}

	/**
	 * In reserved mode, the sublimits of active buyers must add up to no more than the credit limit.
	 *
	 * @param Buyer[] $buyers
	 */
	private function validateReservedTotal(Account $account, array $buyers): bool
	{
		if ($account->getEffectiveSublimitMode() !== SublimitMode::Reserved) {
			return true;
		}

		return $this->reservedTotalFits($account, $buyers);
	}

	/**
	 * @param Buyer[] $buyers
	 */
	private function reservedTotalFits(Account $account, array $buyers): bool
	{
		if (! $account->creditLimit instanceof Money) {
			return true;
		}

		$reserved = Amounts::zero($account->getCurrency());
		foreach ($buyers as $buyer) {
			if ($buyer->active && $buyer->sublimit instanceof Money) {
				$reserved = $reserved->add($buyer->sublimit);
			}
		}

		return $reserved->lessThanOrEqual($account->creditLimit);
	}

	/**
	 * Whether the buyers have ledger entries, invoice billing's history, or credit orders, order billing's.
	 *
	 * @param int[] $buyerIds
	 */
	private function hasBuyerHistory(array $buyerIds): bool
	{
		foreach ([Table::ENTRIES, Table::ORDERS] as $table) {
			$hasRows = (new Query())
				->from($table)
				->where([
					'buyerId' => $buyerIds,
				])
				->exists();

			if ($hasRows) {
				return true;
			}
		}

		return false;
	}
}
