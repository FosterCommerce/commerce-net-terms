<?php

declare(strict_types=1);

namespace fostercommerce\netterms\services;

use Craft;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\elements\db\OrderQuery;
use craft\commerce\elements\Order;
use craft\commerce\errors\TransactionException;
use craft\commerce\helpers\Currency as CurrencyHelper;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\db\Paginator;
use craft\db\Query;
use craft\db\Table as CraftTable;
use DateTime;
use DateTimeInterface;
use fostercommerce\netterms\db\Table;
use fostercommerce\netterms\errors\AccountBusyException;
use fostercommerce\netterms\errors\InvalidAmountException;
use fostercommerce\netterms\errors\LedgerException;
use fostercommerce\netterms\fields\PaymentTerms;
use fostercommerce\netterms\gateways\NetTerms;
use fostercommerce\netterms\helpers\Amounts;
use fostercommerce\netterms\models\Account;
use fostercommerce\netterms\models\Application;
use fostercommerce\netterms\models\Buyer;
use fostercommerce\netterms\models\Entry;
use fostercommerce\netterms\models\Payment;
use fostercommerce\netterms\Plugin;
use fostercommerce\netterms\records\ApplicationRecord;
use fostercommerce\netterms\records\CreditOrderRecord;
use Money\Currency;
use Money\Money;
use yii\base\Component;
use yii\base\InvalidConfigException;

/**
 * Orders charged to an account: payments applied to them in order billing, and changes to their totals in invoice billing. In order billing the order and its transactions are the record of what is owed and paid.
 */
class CreditOrders extends Component
{
	/**
	 * The `code` on refund transactions a reversal writes, so sites can tell a correction from money returned to the payer.
	 */
	public const REVERSAL_TRANSACTION_CODE = 'net-terms-reversal';

	/**
	 * Link an authorized order to its account and buyer. Writing it twice has no effect.
	 */
	public function linkOrder(Order $order, Buyer $buyer): void
	{
		if ($this->isCreditOrder($order)) {
			return;
		}

		$record = new CreditOrderRecord();
		$record->orderId = (int) $order->id;
		$record->accountId = (int) $buyer->accountId;
		$record->buyerId = (int) $buyer->id;
		$record->save(false);
	}

	public function isCreditOrder(Order $order): bool
	{
		return CreditOrderRecord::find()->where([
			'orderId' => $order->id,
		])->exists();
	}

	public function getAccountForOrder(Order $order): ?Account
	{
		$record = CreditOrderRecord::findOne([
			'orderId' => $order->id,
		]);

		return $record instanceof CreditOrderRecord ? Plugin::getInstance()->getAccounts()->getAccountById($record->accountId) : null;
	}

	public function getBuyerIdForOrder(int $orderId): ?int
	{
		return CreditOrderRecord::findOne([
			'orderId' => $orderId,
		])?->buyerId;
	}

	/**
	 * The account’s credit orders, newest first.
	 */
	public function createOrderQuery(Account $account): OrderQuery
	{
		return Order::find()
			->andWhere([
				'commerce_orders.id' => (new Query())
					->select(['orderId'])
					->from(Table::ORDERS)
					->where([
						'accountId' => $account->id,
					]),
			])
			->orderBy([
				'dateOrdered' => SORT_DESC,
				'commerce_orders.id' => SORT_DESC,
			]);
	}

	public function getOrderPaginator(Account $account, int $currentPage, int $pageSize): Paginator
	{
		return new Paginator($this->createOrderQuery($account), [
			'currentPage' => $currentPage,
			'pageSize' => $pageSize,
		]);
	}

	/**
	 * The account’s credit orders with a balance left to pay, oldest first.
	 *
	 * @return Order[]
	 */
	public function getOpenOrders(Account $account): array
	{
		return $this->createOrderQuery($account)
			->isUnpaid()
			->orderBy([
				'dateOrdered' => SORT_ASC,
				'commerce_orders.id' => SORT_ASC,
			])
			->all();
	}

	/**
	 * What each buyer’s credit orders still owe, from Commerce’s stored order totals, keyed by buyer ID.
	 *
	 * @return array<int, Money>
	 */
	public function getOwedByBuyerId(Account $account): array
	{
		$sumsByBuyerId = (new Query())
			->select([
				'creditOrders.buyerId',
				// Count only what is unpaid, since an overpaid order owes the buyer and doesn't settle their other orders
				'SUM(CASE WHEN [[orders.totalPrice]] > [[orders.totalPaid]] THEN [[orders.totalPrice]] - [[orders.totalPaid]] ELSE 0 END)',
			])
			->from([
				'creditOrders' => Table::ORDERS,
			])
			->innerJoin([
				'orders' => CommerceTable::ORDERS,
			], '[[orders.id]] = [[creditOrders.orderId]]')
			// Skip trashed orders, since their rows in netterms_orders stay until garbage collection
			->innerJoin([
				'elements' => CraftTable::ELEMENTS,
			], '[[elements.id]] = [[creditOrders.orderId]]')
			->where([
				'creditOrders.accountId' => $account->id,
				'elements.dateDeleted' => null,
			])
			->groupBy(['creditOrders.buyerId'])
			->pairs();

		return Amounts::fromSums($sumsByBuyerId, $account->getCurrency());
	}

	/**
	 * The order’s reference, or its short number before it has one.
	 */
	public function getLabel(Order $order): string
	{
		return $order->reference ?? $order->getShortNumber();
	}

	/**
	 * @throws InvalidConfigException if the order’s store has no currency
	 */
	public function getBalance(Order $order): Money
	{
		$currency = $order->getStore()->getCurrency() ?? throw new InvalidConfigException('The order’s store has no currency.');

		return Amounts::toMoney((string) $order->getOutstandingBalance(), $currency);
	}

	public function getDateDue(Order $order, Account $account): ?DateTime
	{
		if (! $order->dateOrdered instanceof DateTimeInterface) {
			return null;
		}

		return DateTime::createFromInterface($order->dateOrdered)->modify('+' . $this->getPaymentTerms($order, $account) . ' days');
	}

	/**
	 * Days from the order date to its due date: the order’s Payment terms field when it has a value, else the account’s terms.
	 */
	public function getPaymentTerms(Order $order, Account $account): int
	{
		foreach ($order->getFieldLayout()->getCustomFields() as $field) {
			if ($field instanceof PaymentTerms) {
				$days = $order->getFieldValue((string) $field->handle);

				return is_int($days) && $days >= 0 ? $days : $account->getEffectivePaymentTerms();
			}
		}

		return $account->getEffectivePaymentTerms();
	}

	public function getIsOverdue(Order $order, Account $account): bool
	{
		$dateDue = $this->getDateDue($order, $account);

		return $dateDue instanceof DateTime && $dateDue < new DateTime() && $this->getBalance($order)->isPositive();
	}

	/**
	 * Apply a payment to several orders as Commerce captures, writing a capture for every order or for no order. Every amount is checked before any capture is written.
	 *
	 * @param array<int, Money> $amountsByOrderId
	 * @throws LedgerException if an order isn’t a credit order on the account or isn’t authorized, an amount doesn’t fit, or the account is locked by another request
	 * @throws InvalidConfigException if an order’s store has no currency
	 * @throws TransactionException if Commerce doesn’t save a capture
	 */
	public function applyToOrders(Payment $payment, array $amountsByOrderId): void
	{
		$plugin = Plugin::getInstance();

		$plugin->getLedger()->withAccountLock((int) $payment->accountId, function () use ($plugin, $payment, $amountsByOrderId): void {
			$allocations = [];
			$total = Amounts::zero($payment->amount->getCurrency());

			foreach ($amountsByOrderId as $orderId => $amount) {
				$order = $this->getCommerce()->getOrders()->getOrderById($orderId);

				if (! $order instanceof Order || $this->getAccountForOrder($order)?->id !== $payment->accountId) {
					throw new LedgerException(Craft::t(Plugin::HANDLE, 'payment.invalidOrder'));
				}

				if (! $amount->isPositive()) {
					throw new LedgerException(Craft::t(Plugin::HANDLE, 'application.amountNotPositive'));
				}

				$balance = $this->getBalance($order);
				if ($amount->greaterThan($balance)) {
					throw new LedgerException(Craft::t(Plugin::HANDLE, 'application.overBalance', [
						'balance' => Amounts::toString($balance),
					]));
				}

				$allocations[] = [$order, $amount, $this->getAuthorization($order)];
				$total = $total->add($amount);
			}

			$unapplied = $plugin->getPayments()->getUnapplied($payment);
			if ($total->greaterThan($unapplied)) {
				throw new LedgerException(Craft::t(Plugin::HANDLE, 'application.overUnapplied', [
					'unapplied' => Amounts::toString($unapplied),
				]));
			}

			foreach ($allocations as [$order, $amount, $authorization]) {
				$capture = $this->writeCapture($authorization, $payment, $amount);
				$this->saveApplication($payment, $order, $capture, $amount);
			}

			foreach ($allocations as [$order]) {
				$order->updateOrderPaidInformation();
			}
		});
	}

	/**
	 * Each order application’s figures from its capture: what it still applies, and what was refunded to the payer. A reversal refunds the capture but returns no money.
	 *
	 * @param Application[] $applications
	 * @return array<int, array{applied: Money, refunded: Money}> keyed by application ID
	 */
	public function getFiguresByApplicationId(array $applications, Currency $currency): array
	{
		$captureIds = array_values(array_filter(array_map(static fn (Application $application): ?int => $application->transactionId, $applications)));
		$rows = $captureIds === [] ? [] : (new Query())
			->select(['id', 'parentId', 'type', 'amount', 'code'])
			->from(CommerceTable::TRANSACTIONS)
			->where([
				'or',
				[
					'id' => $captureIds,
				],
				[
					'parentId' => $captureIds,
				],
			])
			->andWhere([
				'status' => TransactionRecord::STATUS_SUCCESS,
			])
			->all();

		/** @var array<int, array{id: int|string, parentId: int|string|null, type: string, amount: string, code: string|null}> $rows */
		$zero = Amounts::zero($currency);
		$figuresByApplicationId = [];

		foreach ($applications as $application) {
			$applied = $zero;
			$refunded = $zero;

			foreach ($rows as $row) {
				$amount = Amounts::toMoney((string) $row['amount'], $currency);

				if ((int) $row['id'] === $application->transactionId) {
					$applied = $applied->add($amount);
				} elseif ((int) $row['parentId'] === $application->transactionId && $row['type'] === TransactionRecord::TYPE_REFUND) {
					$applied = $applied->subtract($amount);
					$refunded = $row['code'] === self::REVERSAL_TRANSACTION_CODE ? $refunded : $refunded->add($amount);
				}
			}

			$figuresByApplicationId[(int) $application->id] = [
				'applied' => $applied,
				'refunded' => $refunded,
			];
		}

		return $figuresByApplicationId;
	}

	/**
	 * Refund what is left of an application’s capture, marked as a reversal, so the amount counts as the payment’s unapplied credit again.
	 *
	 * @throws LedgerException if the capture has no amount left to reverse, the order is in the trash, or the account is locked by another request
	 * @throws TransactionException if Commerce doesn’t save the refund
	 */
	public function reverseApplication(Application $application, Payment $payment): void
	{
		$currency = $payment->amount->getCurrency();

		Plugin::getInstance()->getLedger()->withAccountLock((int) $payment->accountId, function () use ($application, $currency): void {
			$applied = $this->getFiguresByApplicationId([$application], $currency)[(int) $application->id]['applied'];
			if (! $applied->isPositive()) {
				throw new LedgerException(Craft::t(Plugin::HANDLE, 'application.nothingToReverse'));
			}

			// Refuse an order in the trash, since the capture's refund needs its order and a trashed order doesn't load
			if (! Order::find()->id($application->orderId)->exists()) {
				throw new LedgerException(Craft::t(Plugin::HANDLE, 'application.orderTrashed'));
			}

			/** @var Transaction $capture */
			$capture = $this->getCommerce()->getTransactions()->getTransactionById((int) $application->transactionId);
			$this->writeRefund($capture, $applied, Craft::t(Plugin::HANDLE, 'transaction.reversed'), self::REVERSAL_TRANSACTION_CODE);

			/** @var Order $order */
			$order = $capture->getOrder();
			$order->updateOrderPaidInformation();
		});
	}

	/**
	 * Settle a change to a completed credit order’s total, in invoice billing.
	 *
	 * @param string $editKey written as the transactions’ reference, so a retried job finds the edit already settled
	 * @throws AccountBusyException if the account is locked by another request
	 * @throws LedgerException if the order has no Net Terms purchase
	 * @throws InvalidAmountException if a raised total doesn’t fit the buyer’s available credit
	 * @throws TransactionException if Commerce doesn’t save a transaction
	 */
	public function followTotalChange(int $orderId, string $changeDecimal, string $editKey, ?int $authorId): void
	{
		$ledger = Plugin::getInstance()->getLedger();
		$charge = $ledger->getChargeByOrderId($orderId);

		if (! $charge instanceof Entry) {
			return;
		}

		$ledger->withAccountLock((int) $charge->accountId, function () use ($orderId, $charge, $changeDecimal, $editKey, $authorId): void {
			// Load the order inside the lock, since a payment saved meanwhile changes its balance
			// Include a trashed order, which has to match the ledger when restored
			$order = Order::find()->id($orderId)->trashed(null)->one();

			// Skip an order deleted for good since the edit
			if (! $order instanceof Order) {
				return;
			}

			// Match the edit key among the order's own transactions, since Commerce doesn't index the reference column
			foreach ($order->getTransactions() as $transaction) {
				if ($transaction->reference === $editKey) {
					return;
				}
			}

			$currency = $charge->amount->getCurrency();
			$change = Amounts::toMoney($changeDecimal, $currency);
			$outstanding = Amounts::toMoney((string) $order->getOutstandingBalance(), $currency);

			// Charge only what the order still owes, since a payment taken after the edit covers the rest
			if ($change->isPositive()) {
				$this->chargeRaise($order, $charge, Money::min($change, $outstanding), $editKey, $authorId);
			} else {
				// Refund only what the order is overpaid, since a refund given before the edit already returned the rest
				$this->refundCut($order, $charge, Money::min($change->negative(), $outstanding->negative()), $editKey, $authorId);
			}

			$order->updateOrderPaidInformation();
		});
	}

	/**
	 * Add an error to a completed credit order whose raised total doesn’t fit the buyer’s available credit, so the order editor shows the reason and doesn’t save the order. A suspended account or deactivated buyer isn’t checked.
	 */
	public function validateTotalChange(Order $order): void
	{
		// Skip the credit check in full recalculation mode, since the totals don't include the new adjustments until the save
		if (! $order->isCompleted || $order->getRecalculationMode() === Order::RECALCULATION_MODE_ALL) {
			return;
		}

		$charge = Plugin::getInstance()->getLedger()->getChargeByOrderId((int) $order->id);
		if (! $charge instanceof Entry) {
			return;
		}

		$currency = $charge->amount->getCurrency();
		$raise = Amounts::toMoney((string) $order->getTotalPrice(), $currency)->subtract(Amounts::toMoney($this->getStoredTotal((int) $order->id), $currency));
		$error = $this->getRaiseOverCreditError($charge, Money::min($raise, Amounts::toMoney((string) $order->getOutstandingBalance(), $currency)));

		if ($error !== null) {
			$order->addError('totalPrice', $error);
		}
	}

	/**
	 * The order’s total as last saved, before a save in progress recalculates it.
	 */
	public function getStoredTotal(int $orderId): string
	{
		return (string) (new Query())
			->select(['totalPrice'])
			->from(CommerceTable::ORDERS)
			->where([
				'id' => $orderId,
			])
			->scalar();
	}

	/**
	 * @throws InvalidAmountException if the amount doesn’t fit the buyer’s available credit
	 */
	private function chargeRaise(Order $order, Entry $charge, Money $amount, string $editKey, ?int $authorId): void
	{
		if (! $amount->isPositive()) {
			return;
		}

		$error = $this->getRaiseOverCreditError($charge, $amount);
		if ($error !== null) {
			throw new InvalidAmountException($error);
		}

		$transactions = $this->getCommerce()->getTransactions();
		$addition = $transactions->createTransaction(null, $this->getNetTermsPurchases($order)[0], TransactionRecord::TYPE_PURCHASE);
		$addition->parentId = null;
		$this->setTransactionAmount($addition, $amount);
		$addition->status = TransactionRecord::STATUS_SUCCESS;
		$addition->reference = $editKey;
		$addition->message = Craft::t(Plugin::HANDLE, 'transaction.orderChanged');
		$this->saveTransaction($addition);

		/** @var Buyer $buyer */
		$buyer = Plugin::getInstance()->getAccounts()->getBuyerById((int) $charge->buyerId);
		Plugin::getInstance()->getLedger()->charge($buyer, $amount, (int) $order->id, (string) $addition->hash, $authorId);
	}

	/**
	 * Refund newest Net Terms purchase first, each up to what it has left.
	 */
	private function refundCut(Order $order, Entry $charge, Money $amount, string $editKey, ?int $authorId): void
	{
		foreach (array_reverse($this->getNetTermsPurchases($order)) as $purchase) {
			if (! $amount->isPositive()) {
				return;
			}

			$refundAmount = Money::min($amount, $this->getRefundable($order, $purchase, $amount->getCurrency()));
			if (! $refundAmount->isPositive()) {
				continue;
			}

			$refund = $this->writeRefund($purchase, $refundAmount, Craft::t(Plugin::HANDLE, 'transaction.orderChanged'), reference: $editKey);
			Plugin::getInstance()->getLedger()->changeOrder($charge, $refundAmount->negative(), (string) $refund->hash, $authorId);
			$amount = $amount->subtract($refundAmount);
		}
	}

	/**
	 * What a purchase has left to refund, in the store currency, since Commerce’s own figure is in the payment currency.
	 */
	private function getRefundable(Order $order, Transaction $purchase, Currency $currency): Money
	{
		$refundable = Amounts::toMoney((string) $purchase->amount, $currency);

		foreach ($order->getTransactions() as $transaction) {
			if ($transaction->parentId === $purchase->id && $transaction->type === TransactionRecord::TYPE_REFUND && $transaction->status === TransactionRecord::STATUS_SUCCESS) {
				$refundable = $refundable->subtract(Amounts::toMoney((string) $transaction->amount, $currency));
			}
		}

		return $refundable;
	}

	/**
	 * The reason a raise doesn’t fit the available credit of the charge’s buyer, or null when it fits or the credit check doesn’t apply.
	 */
	private function getRaiseOverCreditError(Entry $charge, Money $amount): ?string
	{
		/** @var Buyer $buyer */
		$buyer = Plugin::getInstance()->getAccounts()->getBuyerById((int) $charge->buyerId);

		// Skip the credit check for a suspended account or a deactivated buyer, whose available credit is always zero
		if (! $buyer->active || ! $buyer->getAccount()->getIsActive()) {
			return null;
		}

		$available = Plugin::getInstance()->getAccounts()->getAvailableCredit($buyer);
		if (! $amount->isPositive() || ! $available instanceof Money || $available->greaterThanOrEqual($amount)) {
			return null;
		}

		return Craft::t(Plugin::HANDLE, 'order.raiseOverCredit', [
			'amount' => Amounts::toString($amount),
			'buyer' => $buyer->getName(),
			'available' => Amounts::toString($available),
		]);
	}

	/**
	 * @throws TransactionException if Commerce doesn’t save the transaction
	 */
	private function saveTransaction(Transaction $transaction): void
	{
		if (! $this->getCommerce()->getTransactions()->saveTransaction($transaction)) {
			throw new TransactionException('Error saving transaction: ' . implode(', ', $transaction->getFirstErrors()));
		}
	}

	/**
	 * @throws LedgerException if the order has no Net Terms authorization
	 */
	private function getAuthorization(Order $order): Transaction
	{
		return $this->getNetTermsTransactions($order, TransactionRecord::TYPE_AUTHORIZE)[0]
			?? throw new LedgerException(Craft::t(Plugin::HANDLE, 'payment.notAuthorized'));
	}

	/**
	 * The order’s successful Net Terms purchases, oldest first.
	 *
	 * @return non-empty-list<Transaction>
	 * @throws LedgerException if the order has no Net Terms purchase
	 */
	private function getNetTermsPurchases(Order $order): array
	{
		$purchases = $this->getNetTermsTransactions($order, TransactionRecord::TYPE_PURCHASE);

		return $purchases !== [] ? $purchases : throw new LedgerException(Craft::t(Plugin::HANDLE, 'gateway.chargeNotFound'));
	}

	/**
	 * The order’s successful Net Terms transactions of the type, oldest first.
	 *
	 * @return list<Transaction>
	 */
	private function getNetTermsTransactions(Order $order, string $type): array
	{
		return array_values(array_filter(
			$order->getTransactions(),
			static fn (Transaction $transaction): bool => $transaction->type === $type
				&& $transaction->status === TransactionRecord::STATUS_SUCCESS
				&& $transaction->getGateway() instanceof NetTerms,
		));
	}

	private function writeCapture(Transaction $authorization, Payment $payment, Money $amount): Transaction
	{
		$transactions = $this->getCommerce()->getTransactions();
		$capture = $transactions->createTransaction(null, $authorization, TransactionRecord::TYPE_CAPTURE);
		$this->setTransactionAmount($capture, $amount);
		$capture->status = TransactionRecord::STATUS_SUCCESS;
		$capture->reference = $payment->reference ?? '';
		$capture->message = Craft::t(Plugin::HANDLE, 'transaction.recorded', [
			'method' => $payment->method->label(),
		]);
		$this->saveTransaction($capture);

		return $capture;
	}

	private function writeRefund(Transaction $parent, Money $amount, string $message, ?string $code = null, string $reference = ''): Transaction
	{
		$transactions = $this->getCommerce()->getTransactions();
		$refund = $transactions->createTransaction(null, $parent, TransactionRecord::TYPE_REFUND);
		$this->setTransactionAmount($refund, $amount);
		$refund->status = TransactionRecord::STATUS_SUCCESS;
		$refund->message = $message;
		$refund->code = $code;
		$refund->reference = $reference;
		$this->saveTransaction($refund);

		return $refund;
	}

	/**
	 * Set a transaction’s amount, and its payment amount at its rate.
	 */
	private function setTransactionAmount(Transaction $transaction, Money $amount): void
	{
		$transaction->amount = (float) Amounts::toDecimal($amount);
		$transaction->paymentAmount = CurrencyHelper::round((float) Amounts::toDecimal($amount->multiply((string) $transaction->paymentRate)), (string) $transaction->paymentCurrency);
	}

	private function saveApplication(Payment $payment, Order $order, Transaction $capture, Money $amount): void
	{
		$record = new ApplicationRecord();
		$record->paymentId = (int) $payment->id;
		$record->orderId = (int) $order->id;
		$record->transactionId = (int) $capture->id;
		$record->amount = Amounts::toDecimal($amount);
		$record->authorId = Craft::$app->getUser()->getIdentity()?->id;
		$record->save(false);
	}

	private function getCommerce(): Commerce
	{
		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();

		return $commerce;
	}
}
