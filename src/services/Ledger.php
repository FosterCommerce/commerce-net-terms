<?php

declare(strict_types=1);

namespace fostercommerce\netterms\services;

use Craft;
use craft\commerce\elements\Order;
use craft\db\Paginator;
use craft\db\Query;
use craft\db\Table as CraftTable;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
use fostercommerce\netterms\db\Table;
use fostercommerce\netterms\enums\EntryStatus;
use fostercommerce\netterms\enums\EntryType;
use fostercommerce\netterms\errors\AccountBusyException;
use fostercommerce\netterms\errors\LedgerException;
use fostercommerce\netterms\events\EntryEvent;
use fostercommerce\netterms\helpers\Amounts;
use fostercommerce\netterms\models\Account;
use fostercommerce\netterms\models\Application;
use fostercommerce\netterms\models\Buyer;
use fostercommerce\netterms\models\Entry;
use fostercommerce\netterms\models\InvoiceLine;
use fostercommerce\netterms\Plugin;
use fostercommerce\netterms\records\EntryRecord;
use Money\Currency;
use Money\Money;
use Throwable;
use yii\base\Component;

/**
 * The ledger and authorization lifecycle, and the only writer of entries.
 */
class Ledger extends Component
{
	/**
	 * The event that is triggered after an entry is written and its database transaction commits.
	 *
	 * ```php
	 * use fostercommerce\netterms\events\EntryEvent;
	 * use fostercommerce\netterms\services\Ledger;
	 * use yii\base\Event;
	 *
	 * Event::on(Ledger::class, Ledger::EVENT_AFTER_ADD_ENTRY, function(EntryEvent $event) {
	 *     $entry = $event->entry;
	 *     // Mirror the entry to an accounting system
	 * });
	 * ```
	 */
	public const EVENT_AFTER_ADD_ENTRY = 'afterAddEntry';

	private const LOCK_TIMEOUT = 15;

	/**
	 * @var array<int, true>
	 */
	private array $heldLocks = [];

	/**
	 * @var Entry[]
	 */
	private array $uncommittedEntries = [];

	/**
	 * @return Entry[]
	 */
	public function getEntriesByAccountId(int $accountId): array
	{
		/** @var EntryRecord[] $records */
		$records = EntryRecord::find()
			->where([
				'accountId' => $accountId,
			])
			->andWhere($this->notTrashedOrderCondition())
			->orderBy([
				'dateCreated' => SORT_DESC,
				'id' => SORT_DESC,
			])
			->all();

		return array_map($this->createEntryFromRecord(...), $records);
	}

	/**
	 * One page of an account's entries, newest first. The page results are `Entry` models.
	 */
	public function getEntryPaginator(int $accountId, int $currentPage, int $pageSize): Paginator
	{
		$paginator = new Paginator(
			EntryRecord::find()
				->where([
					'accountId' => $accountId,
				])
				->andWhere($this->notTrashedOrderCondition())
				->orderBy([
					'dateCreated' => SORT_DESC,
					'id' => SORT_DESC,
				]),
			[
				'currentPage' => $currentPage,
				'pageSize' => $pageSize,
			],
		);

		/** @var EntryRecord[] $records */
		$records = $paginator->getPageResults();
		$paginator->setPageResults(array_map($this->createEntryFromRecord(...), $records));

		return $paginator;
	}

	/**
	 * @return Entry[]
	 */
	public function getEntriesByInvoiceLineId(int $invoiceLineId): array
	{
		/** @var EntryRecord[] $records */
		$records = EntryRecord::find()
			->where([
				'invoiceLineId' => $invoiceLineId,
			])
			->orderBy([
				'dateCreated' => SORT_ASC,
				'id' => SORT_ASC,
			])
			->all();

		return array_map($this->createEntryFromRecord(...), $records);
	}

	/**
	 * Which of the given orders have entries on an issued invoice. Voiding an invoice takes its entries off it.
	 *
	 * @param int[] $orderIds
	 * @return int[]
	 */
	public function getInvoicedOrderIds(array $orderIds): array
	{
		return array_map(intval(...), EntryRecord::find()
			->select(['orderId'])
			->distinct()
			->where([
				'orderId' => $orderIds,
			])
			->andWhere([
				'not',
				[
					'invoiceLineId' => null,
				],
			])
			->column());
	}

	public function getChargeByTransactionHash(string $transactionHash): ?Entry
	{
		$record = EntryRecord::findOne([
			'transactionHash' => $transactionHash,
			'type' => EntryType::Charge->value,
			'status' => EntryStatus::postedValues(),
		]);

		return $record instanceof EntryRecord ? $this->createEntryFromRecord($record) : null;
	}

	public function getChargeByOrderId(int $orderId): ?Entry
	{
		/** @var EntryRecord|null $record */
		$record = EntryRecord::find()
			->where([
				'orderId' => $orderId,
				'type' => EntryType::Charge->value,
				'status' => EntryStatus::postedValues(),
			])
			->orderBy([
				'id' => SORT_ASC,
			])
			->one();

		return $record instanceof EntryRecord ? $this->createEntryFromRecord($record) : null;
	}

	/**
	 * Each buyer’s balance on an account, keyed by buyer ID. Entries without a buyer are keyed by 0.
	 *
	 * @return array<int, Money>
	 */
	public function getBalanceByBuyers(Account $account): array
	{
		$rows = (new Query())
			->select([
				'buyerId',
				'balance' => 'SUM([[amount]])',
			])
			->from(Table::ENTRIES)
			->where([
				'status' => EntryStatus::postedValues(),
				'accountId' => $account->id,
			])
			->andWhere($this->notTrashedOrderCondition())
			->groupBy(['buyerId'])
			->all();

		/** @var array<int, array{buyerId: int|string|null, balance: string|null}> $rows */
		$currency = $account->getCurrency();
		$balanceByBuyerId = [];
		foreach ($rows as $row) {
			$balanceByBuyerId[(int) $row['buyerId']] = Amounts::fromSum($row['balance'], $currency);
		}

		return $balanceByBuyerId;
	}

	/**
	 * Each invoice's charges, refunds and adjustments, summed and keyed by invoice ID.
	 *
	 * @param int[] $invoiceIds
	 * @return array<int, Money>
	 */
	public function getAmountsByInvoiceId(array $invoiceIds, Currency $currency): array
	{
		$sumsByInvoiceId = (new Query())
			->select([
				'lines.invoiceId',
				'SUM([[entries.amount]])',
			])
			->from([
				'entries' => Table::ENTRIES,
			])
			->innerJoin([
				'lines' => Table::INVOICE_LINES,
			], '[[lines.id]] = [[entries.invoiceLineId]]')
			->where([
				'entries.status' => EntryStatus::postedValues(),
				'lines.invoiceId' => $invoiceIds,
			])
			->groupBy(['lines.invoiceId'])
			->pairs();

		return Amounts::fromSums($sumsByInvoiceId, $currency);
	}

	/**
	 * Each invoice line's charges, refunds and adjustments, summed and keyed by invoice line ID.
	 *
	 * @param int[] $invoiceLineIds
	 * @return array<int, Money>
	 */
	public function getAmountsByInvoiceLineId(array $invoiceLineIds, Currency $currency): array
	{
		$sumsByInvoiceLineId = (new Query())
			->select([
				'invoiceLineId',
				'SUM([[amount]])',
			])
			->from(Table::ENTRIES)
			->where([
				'status' => EntryStatus::postedValues(),
				'invoiceLineId' => $invoiceLineIds,
			])
			->groupBy(['invoiceLineId'])
			->pairs();

		return Amounts::fromSums($sumsByInvoiceLineId, $currency);
	}

	/**
	 * Uninvoiced charges, refunds and adjustments made up to the cutoff, summed and keyed by buyer ID, with 0 for entries without a buyer.
	 *
	 * @return array<int, Money>
	 */
	public function getUninvoicedByBuyers(Account $account, DateTime $cutoff): array
	{
		$rows = (new Query())
			->select([
				'buyerId',
				'amount' => 'SUM([[amount]])',
			])
			->from(Table::ENTRIES)
			->where([
				'status' => EntryStatus::postedValues(),
				'accountId' => $account->id,
				'invoiceLineId' => null,
				'type' => $this->invoicedTypeValues(),
			])
			->andWhere(['<=', 'dateCreated', Db::prepareDateForDb($cutoff)])
			->andWhere($this->notTrashedOrderCondition())
			->andWhere($this->completedPartialPaymentCondition((int) $account->id))
			->groupBy(['buyerId'])
			->all();

		/** @var array<int, array{buyerId: int|string|null, amount: string|null}> $rows */
		$currency = $account->getCurrency();
		$uninvoicedByBuyerId = [];
		foreach ($rows as $row) {
			$uninvoicedByBuyerId[(int) $row['buyerId']] = Amounts::fromSum($row['amount'], $currency);
		}

		return $uninvoicedByBuyerId;
	}

	/**
	 * @throws LedgerException if the account is locked by another request
	 */
	public function getAuthorization(string $authorizationHash): ?Entry
	{
		$record = EntryRecord::findOne([
			'authorizationHash' => $authorizationHash,
		]);

		return $record instanceof EntryRecord ? $this->createEntryFromRecord($record) : null;
	}

	/**
	 * @return array<int, Money>
	 */
	public function getReservedByBuyers(Account $account): array
	{
		/** @var array<array{buyerId: int|string|null, amount: string|null}> $rows */
		$rows = (new Query())
			->select([
				'buyerId',
				'amount' => 'SUM([[amount]])',
			])
			->from(Table::ENTRIES)
			->where([
				'accountId' => $account->id,
				'status' => EntryStatus::Authorized->value,
			])
			->andWhere($this->notTrashedOrderCondition())
			->groupBy(['buyerId'])
			->all();

		return collect($rows)
			->mapWithKeys(static fn (array $row): array => [
				(int) $row['buyerId'] => Amounts::fromSum($row['amount'], $account->getCurrency()),
			])
			->all();
	}

	public function authorize(Buyer $buyer, Money $amount, int $orderId, string $authorizationHash): Entry
	{
		return $this->withAccountLock((int) $buyer->accountId, function () use ($buyer, $amount, $orderId, $authorizationHash): Entry {
			$existing = $this->getAuthorization($authorizationHash);
			if ($existing instanceof Entry) {
				if ($existing->accountId !== $buyer->accountId || $existing->orderId !== $orderId || ! $existing->amount->equals($amount) || $existing->status === EntryStatus::Released) {
					throw new LedgerException('The authorization no longer matches this payment.');
				}

				return $existing;
			}

			$this->requireAuthorizationCredit($buyer, $amount);

			return $this->addEntry(new Entry([
				'accountId' => $buyer->accountId,
				'buyerId' => $buyer->id,
				'type' => EntryType::Charge,
				'status' => EntryStatus::Authorized,
				'amount' => $amount,
				'orderId' => $orderId,
				'authorizationHash' => $authorizationHash,
				'transactionHash' => $authorizationHash,
			]));
		});
	}

	public function resizeAuthorization(Entry $authorization, Money $amount): ?Entry
	{
		return $this->withAccountLock((int) $authorization->accountId, function () use ($authorization, $amount): ?Entry {
			$record = $this->authorizationRecord($authorization);
			if ($record->status !== EntryStatus::Authorized->value) {
				throw new LedgerException('This authorization is no longer active.');
			}

			if ($amount->isZero()) {
				$this->releaseAuthorization($authorization);

				return null;
			}

			$buyer = Plugin::getInstance()->getAccounts()->getBuyerById((int) $record->buyerId);
			if (! $buyer instanceof Buyer) {
				throw new LedgerException(Craft::t(Plugin::HANDLE, 'gateway.notABuyer'));
			}

			$oldAmount = Amounts::toMoney($record->amount, $amount->getCurrency());
			$this->requireAuthorizationCredit($buyer, $amount, $oldAmount);
			$record->amount = Amounts::toDecimal($amount);
			$record->save(false);

			return $this->createEntryFromRecord($record);
		});
	}

	public function releaseAuthorization(Entry $authorization): void
	{
		$this->withAccountLock((int) $authorization->accountId, function () use ($authorization): void {
			$record = $this->authorizationRecord($authorization);
			if ($record->status === EntryStatus::Captured->value) {
				throw new LedgerException('A captured authorization must be refunded.');
			}

			if ($record->delete() === false) {
				throw new LedgerException('The authorization could not be released.');
			}
		});
	}

	public function captureAuthorization(Entry $authorization, Money $amount, string $transactionHash): Entry
	{
		return $this->withAccountLock((int) $authorization->accountId, function () use ($authorization, $amount, $transactionHash): Entry {
			$record = $this->authorizationRecord($authorization);
			$entry = $this->createEntryFromRecord($record);
			if (! $entry->amount->equals($amount)) {
				throw new LedgerException('The capture amount must match the reserved credit.');
			}

			if ($entry->status === EntryStatus::Captured) {
				if ($entry->transactionHash !== $transactionHash) {
					throw new LedgerException('This authorization has already been captured.');
				}

				return $entry;
			}

			if ($entry->status !== EntryStatus::Authorized) {
				throw new LedgerException('This authorization is no longer active.');
			}

			$record->status = EntryStatus::Captured->value;
			$record->transactionHash = $transactionHash;
			$record->save(false);
			$entry = $this->createEntryFromRecord($record);
			$this->uncommittedEntries[] = $entry;

			return $entry;
		});
	}

	public function charge(Buyer $buyer, Money $amount, int $orderId, string $transactionHash, ?int $authorId = null): Entry
	{
		return $this->addEntry(new Entry([
			'accountId' => $buyer->accountId,
			'buyerId' => $buyer->id,
			'type' => EntryType::Charge,
			'amount' => $amount,
			'orderId' => $orderId,
			'transactionHash' => $transactionHash,
			'authorId' => $authorId,
		]));
	}

	/**
	 * Write a refund against a charge, on the charge’s invoice line when that line’s balance covers it.
	 *
	 * @throws LedgerException if the account is locked by another request, or the refund is more than the order has left charged
	 */
	public function refund(Entry $charge, Money $amount, string $transactionHash): Entry
	{
		return $this->withAccountLock((int) $charge->accountId, function () use ($charge, $amount, $transactionHash): Entry {
			// Check what the order has left charged under the lock, since two refunds can pass Commerce's own check at once
			$chargedForOrder = Amounts::fromSum(EntryRecord::find()
				->where([
					'status' => EntryStatus::postedValues(),
					'orderId' => $charge->orderId,
					'accountId' => $charge->accountId,
					'type' => [EntryType::Charge->value, EntryType::Refund->value, EntryType::OrderChange->value],
				])
				->sum('[[amount]]'), $amount->getCurrency());
			if ($amount->greaterThan($chargedForOrder)) {
				throw new LedgerException(Craft::t(Plugin::HANDLE, 'gateway.refundOverCharge', [
					'charged' => Amounts::toString($chargedForOrder),
				]));
			}

			return $this->addEntry(new Entry([
				'accountId' => $charge->accountId,
				'buyerId' => $charge->buyerId,
				'type' => EntryType::Refund,
				'amount' => $amount->negative(),
				'orderId' => $charge->orderId,
				'transactionHash' => $transactionHash,
				'invoiceLineId' => $this->getLineIdCovering($charge, $amount),
			]));
		});
	}

	/**
	 * Follow a change to a charged order’s total, so the balance matches the order. A lowered total comes off the charge’s invoice line when that line’s balance covers it.
	 *
	 * @param int|null $authorId the staff member who edited the order, since the queue job that calls this runs with no signed-in user
	 * @throws LedgerException if the account is locked by another request
	 */
	public function changeOrder(Entry $charge, Money $difference, string $transactionHash, ?int $authorId): Entry
	{
		return $this->addEntry(new Entry([
			'accountId' => $charge->accountId,
			'buyerId' => $charge->buyerId,
			'type' => EntryType::OrderChange,
			'amount' => $difference,
			'orderId' => $charge->orderId,
			'transactionHash' => $transactionHash,
			'invoiceLineId' => $difference->isNegative() ? $this->getLineIdCovering($charge, $difference->negative()) : null,
			'authorId' => $authorId,
		]));
	}

	/**
	 * @throws LedgerException if the account is locked by another request
	 */
	public function adjust(Account $account, ?Buyer $buyer, Money $amount, string $note): Entry
	{
		return $this->addEntry(new Entry([
			'accountId' => $account->id,
			'buyerId' => $buyer?->id,
			'type' => EntryType::Adjustment,
			'amount' => $amount,
			'note' => $note,
			'authorId' => Craft::$app->getUser()->getIdentity()?->id,
		]));
	}

	/**
	 * @throws LedgerException if the account is locked by another request
	 */
	public function applyPayment(Application $application, int $accountId, ?int $buyerId): Entry
	{
		return $this->addEntry(new Entry([
			'accountId' => $accountId,
			'buyerId' => $buyerId,
			'type' => EntryType::Payment,
			'amount' => $application->amount->negative(),
			'applicationId' => $application->id,
			'authorId' => Craft::$app->getUser()->getIdentity()?->id,
		]));
	}

	/**
	 * @throws LedgerException if the account is locked by another request
	 */
	public function reverseApplication(Application $application, int $accountId, ?int $buyerId): Entry
	{
		return $this->addEntry(new Entry([
			'accountId' => $accountId,
			'buyerId' => $buyerId,
			'type' => EntryType::Reversal,
			'amount' => $application->amount,
			'applicationId' => $application->id,
			'authorId' => Craft::$app->getUser()->getIdentity()?->id,
		]));
	}

	/**
	 * Put uninvoiced entries made up to the cutoff onto an invoice line.
	 */
	public function assignToInvoiceLine(int $accountId, ?int $buyerId, DateTime $cutoff, int $invoiceLineId): void
	{
		Db::update(Table::ENTRIES, [
			'invoiceLineId' => $invoiceLineId,
		], [
			'and',
			[
				'accountId' => $accountId,
				'buyerId' => $buyerId,
				'invoiceLineId' => null,
				'type' => $this->invoicedTypeValues(),
				'status' => EntryStatus::postedValues(),
			],
			['<=', 'dateCreated', Db::prepareDateForDb($cutoff)],
			$this->notTrashedOrderCondition(),
			$this->completedPartialPaymentCondition($accountId),
		]);
	}

	/**
	 * @param int[] $invoiceLineIds
	 */
	public function unassignFromInvoiceLines(array $invoiceLineIds): void
	{
		Db::update(Table::ENTRIES, [
			'invoiceLineId' => null,
		], [
			'invoiceLineId' => $invoiceLineIds,
		]);
	}

	/**
	 * Run the callback holding the account’s lock and inside one database transaction. Calls nested inside it reuse both.
	 *
	 * Entry events fire once the outermost call commits, and never for entries rolled back.
	 *
	 * @template TReturn
	 * @param callable(): TReturn $callback
	 * @return TReturn
	 * @throws LedgerException if the account is locked by another request
	 * @throws \Throwable whatever the callback throws, after rolling back
	 */
	public function withAccountLock(int $accountId, callable $callback): mixed
	{
		// Reuse the lock this request holds, since acquiring it again fails
		if (isset($this->heldLocks[$accountId])) {
			return $callback();
		}

		$lockName = 'net-terms:account:' . $accountId;
		$mutex = Craft::$app->getMutex();

		if (! $mutex->acquire($lockName, self::LOCK_TIMEOUT)) {
			throw new AccountBusyException(Craft::t(Plugin::HANDLE, 'ledger.accountBusy'));
		}

		$this->heldLocks[$accountId] = true;
		$transaction = Craft::$app->getDb()->beginTransaction();

		try {
			$result = $callback();
			$transaction->commit();
		} catch (Throwable $throwable) {
			$transaction->rollBack();
			$this->uncommittedEntries = [];

			throw $throwable;
		} finally {
			unset($this->heldLocks[$accountId]);
			$mutex->release($lockName);
		}

		$this->triggerCommittedEntryEvents();

		return $result;
	}

	private function authorizationRecord(Entry $authorization): EntryRecord
	{
		$record = EntryRecord::findOne([
			'id' => $authorization->id,
			'authorizationHash' => $authorization->authorizationHash,
		]);
		if (! $record instanceof EntryRecord || $record->authorizationHash === null) {
			throw new LedgerException('The authorization could not be found.');
		}

		return $record;
	}

	private function requireAuthorizationCredit(Buyer $buyer, Money $amount, ?Money $reserved = null): void
	{
		$accounts = Plugin::getInstance()->getAccounts();
		$freshBuyer = $accounts->getBuyerById((int) $buyer->id);
		$account = $accounts->getFreshAccountById((int) $buyer->accountId);
		if (! $freshBuyer instanceof Buyer || ! $freshBuyer->active || ! $account instanceof Account || $account->status !== \fostercommerce\netterms\enums\AccountStatus::Active) {
			throw new LedgerException(Craft::t(Plugin::HANDLE, 'gateway.notABuyer'));
		}

		if (! $amount->isPositive() || ! $amount->getCurrency()->equals($account->getCurrency())) {
			throw new LedgerException('The authorization amount is invalid.');
		}

		$available = $accounts->getAvailableCredit($freshBuyer);
		if ($available instanceof Money && $available->add($reserved ?? Amounts::zero($account->getCurrency()))->lessThan($amount)) {
			throw new LedgerException(Craft::t(Plugin::HANDLE, 'gateway.notEnoughCredit'));
		}
	}

	/**
	 * The charge’s invoice line, when that line’s balance covers the credit.
	 */
	private function getLineIdCovering(Entry $charge, Money $credit): ?int
	{
		$line = $charge->invoiceLineId !== null ? Plugin::getInstance()->getInvoices()->getLineById($charge->invoiceLineId) : null;

		// Leave the credit uninvoiced when it exceeds the line's balance, so the balance never goes negative
		return $line instanceof InvoiceLine && Plugin::getInstance()->getInvoices()->getLineBalance($line)->greaterThanOrEqual($credit)
			? $line->id
			: null;
	}

	/**
	 * @return array<int, mixed>
	 */
	private function completedPartialPaymentCondition(int $accountId): array
	{
		$accountOrderIds = (new Query())
			->select(['orderId'])
			->from(Table::ENTRIES)
			->where([
				'accountId' => $accountId,
			])
			->andWhere([
				'not', [
					'orderId' => null,
				]]);

		$pendingOrderIds = collect(Order::find()
			->andWhere([
				'commerce_orders.id' => $accountOrderIds,
			])
			->isCompleted(false)
			->all())
			->filter(Plugin::getInstance()->getCreditOrders()->hasPartialPaymentHistory(...))
			->map(static fn (Order $order): int => (int) $order->id)
			->all();

		if ($pendingOrderIds === []) {
			return [];
		}

		return ['or', [
			'orderId' => null,
		], ['not in', 'orderId', $pendingOrderIds]];
	}

	/**
	 * Leave out entries for trashed orders until they are restored.
	 *
	 * @return array<int, mixed>
	 */
	private function notTrashedOrderCondition(): array
	{
		return [
			'or',
			[
				'orderId' => null,
			],
			[
				'not',
				[
					'orderId' => (new Query())
						->select(['id'])
						->from(CraftTable::ELEMENTS)
						->where([
							'type' => Order::class,
						])
						->andWhere([
							'not',
							[
								'dateDeleted' => null,
							],
						]),
				],
			],
		];
	}

	private function createEntryFromRecord(EntryRecord $record): Entry
	{
		return new Entry([
			'id' => $record->id,
			'accountId' => $record->accountId,
			'buyerId' => $record->buyerId,
			'type' => EntryType::from($record->type),
			'status' => EntryStatus::from($record->status),
			'authorizationHash' => $record->authorizationHash,
			'amount' => Amounts::toMoney($record->amount, Plugin::getInstance()->getAccounts()->getCurrencyByAccountId($record->accountId)),
			'orderId' => $record->orderId,
			'transactionHash' => $record->transactionHash,
			'invoiceLineId' => $record->invoiceLineId,
			'applicationId' => $record->applicationId,
			'note' => $record->note,
			'authorId' => $record->authorId,
			'dateCreated' => DateTimeHelper::toDateTime($record->dateCreated) ?: null,
		]);
	}

	private function addEntry(Entry $entry): Entry
	{
		return $this->withAccountLock((int) $entry->accountId, function () use ($entry): Entry {
			$record = new EntryRecord();
			$record->accountId = (int) $entry->accountId;
			$record->buyerId = $entry->buyerId;
			$record->type = $entry->type->value;
			$record->status = $entry->status->value;
			$record->authorizationHash = $entry->authorizationHash;
			$record->amount = Amounts::toDecimal($entry->amount);
			$record->orderId = $entry->orderId;
			$record->transactionHash = $entry->transactionHash;
			$record->invoiceLineId = $entry->invoiceLineId;
			$record->applicationId = $entry->applicationId;
			$record->note = $entry->note;
			$record->authorId = $entry->authorId;
			$record->save(false);

			$entry->id = $record->id;
			$entry->dateCreated = DateTimeHelper::toDateTime($record->dateCreated) ?: null;
			if ($entry->status->isPosted()) {
				$this->uncommittedEntries[] = $entry;
			}

			return $entry;
		});
	}

	private function triggerCommittedEntryEvents(): void
	{
		$committedEntries = $this->uncommittedEntries;
		$this->uncommittedEntries = [];

		if (! $this->hasEventHandlers(self::EVENT_AFTER_ADD_ENTRY)) {
			return;
		}

		foreach ($committedEntries as $committedEntry) {
			$this->trigger(self::EVENT_AFTER_ADD_ENTRY, new EntryEvent([
				'entry' => $committedEntry,
			]));
		}
	}

	/**
	 * @return string[]
	 */
	private function invoicedTypeValues(): array
	{
		$values = [];
		foreach (EntryType::cases() as $type) {
			if ($type->isInvoiced()) {
				$values[] = $type->value;
			}
		}

		return $values;
	}
}
