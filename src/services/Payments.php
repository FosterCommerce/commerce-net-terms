<?php

declare(strict_types=1);

namespace fostercommerce\netterms\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\db\Paginator;
use craft\db\Query;
use craft\helpers\ArrayHelper;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use DateTime;
use fostercommerce\netterms\db\Table;
use fostercommerce\netterms\enums\Billing;
use fostercommerce\netterms\enums\PaymentMethod;
use fostercommerce\netterms\errors\LedgerException;
use fostercommerce\netterms\helpers\Amounts;
use fostercommerce\netterms\models\Account;
use fostercommerce\netterms\models\AllocationTarget;
use fostercommerce\netterms\models\Application;
use fostercommerce\netterms\models\Buyer;
use fostercommerce\netterms\models\InvoiceLine;
use fostercommerce\netterms\models\Payment;
use fostercommerce\netterms\Plugin;
use fostercommerce\netterms\records\ApplicationRecord;
use fostercommerce\netterms\records\PaymentRecord;
use Money\Currency;
use Money\Money;
use yii\base\Component;

/**
 * Records payments and applies them to invoice lines or orders.
 */
class Payments extends Component
{
	public function getPaymentById(int $id): ?Payment
	{
		$record = PaymentRecord::findOne($id);

		return $record instanceof PaymentRecord ? $this->createPaymentFromRecord($record) : null;
	}

	/**
	 * @param int[] $ids
	 * @return array<int, Payment> keyed by payment ID
	 */
	public function getPaymentsByIds(array $ids): array
	{
		/** @var PaymentRecord[] $records */
		$records = PaymentRecord::find()
			->where([
				'id' => $ids,
			])
			->indexBy('id')
			->all();

		return array_map($this->createPaymentFromRecord(...), $records);
	}

	/**
	 * @return Payment[]
	 */
	public function getPaymentsByAccountId(int $accountId): array
	{
		/** @var PaymentRecord[] $records */
		$records = PaymentRecord::find()
			->where([
				'accountId' => $accountId,
			])
			->orderBy([
				'dateReceived' => SORT_DESC,
				'id' => SORT_DESC,
			])
			->all();

		return array_map($this->createPaymentFromRecord(...), $records);
	}

	/**
	 * One page of payments, newest first, for one account or every account. The page results are `Payment` models.
	 */
	public function getPaymentPaginator(?int $accountId, int $currentPage, int $pageSize): Paginator
	{
		$paginator = new Paginator(
			PaymentRecord::find()
				->filterWhere([
					'accountId' => $accountId,
				])
				->orderBy([
					'dateReceived' => SORT_DESC,
					'id' => SORT_DESC,
				]),
			[
				'currentPage' => $currentPage,
				'pageSize' => $pageSize,
			],
		);

		/** @var PaymentRecord[] $records */
		$records = $paginator->getPageResults();
		$paginator->setPageResults(array_map($this->createPaymentFromRecord(...), $records));

		return $paginator;
	}

	public function getApplicationById(int $id): ?Application
	{
		$record = ApplicationRecord::findOne($id);

		return $record instanceof ApplicationRecord ? $this->createApplicationFromRecord($record) : null;
	}

	/**
	 * @return Application[]
	 */
	public function getApplicationsByPaymentId(int $paymentId, Currency $currency): array
	{
		/** @var ApplicationRecord[] $records */
		$records = ApplicationRecord::find()
			->where([
				'paymentId' => $paymentId,
			])
			->orderBy([
				'id' => SORT_ASC,
			])
			->all();

		return array_map(fn (ApplicationRecord $record): Application => $this->createApplicationFromRecord($record, $currency), $records);
	}

	/**
	 * @return Application[]
	 */
	public function getActiveApplicationsByLineId(int $invoiceLineId, Currency $currency): array
	{
		/** @var ApplicationRecord[] $records */
		$records = ApplicationRecord::find()
			->where([
				'invoiceLineId' => $invoiceLineId,
				'dateReversed' => null,
			])
			->orderBy([
				'id' => SORT_ASC,
			])
			->all();

		return array_map(fn (ApplicationRecord $record): Application => $this->createApplicationFromRecord($record, $currency), $records);
	}

	/**
	 * Active applications to each invoice, summed and keyed by invoice ID.
	 *
	 * @param int[] $invoiceIds
	 * @return array<int, Money>
	 */
	public function getAppliedByInvoiceId(array $invoiceIds, Currency $currency): array
	{
		$sumsByInvoiceId = (new Query())
			->select([
				'lines.invoiceId',
				'SUM([[applications.amount]])',
			])
			->from([
				'applications' => Table::APPLICATIONS,
			])
			->innerJoin([
				'lines' => Table::INVOICE_LINES,
			], '[[lines.id]] = [[applications.invoiceLineId]]')
			->where([
				'lines.invoiceId' => $invoiceIds,
				'applications.dateReversed' => null,
			])
			->groupBy(['lines.invoiceId'])
			->pairs();

		return Amounts::fromSums($sumsByInvoiceId, $currency);
	}

	/**
	 * Active applications to each invoice line, summed and keyed by invoice line ID.
	 *
	 * @param int[] $invoiceLineIds
	 * @return array<int, Money>
	 */
	public function getAppliedByInvoiceLineId(array $invoiceLineIds, Currency $currency): array
	{
		$sumsByInvoiceLineId = (new Query())
			->select([
				'invoiceLineId',
				'SUM([[amount]])',
			])
			->from(Table::APPLICATIONS)
			->where([
				'invoiceLineId' => $invoiceLineIds,
				'dateReversed' => null,
			])
			->groupBy(['invoiceLineId'])
			->pairs();

		return Amounts::fromSums($sumsByInvoiceLineId, $currency);
	}

	/**
	 * What is left of a payment once its applications and refunds are taken off.
	 */
	public function getUnapplied(Payment $payment): Money
	{
		return $this->getFiguresByPaymentId([$payment])[(int) $payment->id]['unapplied'];
	}

	/**
	 * What Commerce refunded to the payer from the payment’s captures, in order billing.
	 */
	public function getRefunded(Payment $payment): Money
	{
		return $this->getFiguresByPaymentId([$payment])[(int) $payment->id]['refunded'];
	}

	/**
	 * What is left of each payment and what was refunded from it, keyed by payment ID. Applications to invoice lines count until reversed. Applications to orders count what their captures still apply, plus what was refunded to the payer.
	 *
	 * @param Payment[] $payments
	 * @return array<int, array{unapplied: Money, refunded: Money}>
	 */
	public function getFiguresByPaymentId(array $payments): array
	{
		$paymentIds = array_map(static fn (Payment $payment): int => (int) $payment->id, $payments);
		$lineSumsByPaymentId = (new Query())
			->select([
				'paymentId',
				'SUM([[amount]])',
			])
			->from(Table::APPLICATIONS)
			->where([
				'paymentId' => $paymentIds,
				'dateReversed' => null,
			])
			->andWhere([
				'not',
				[
					'invoiceLineId' => null,
				],
			])
			->groupBy(['paymentId'])
			->pairs();
		$orderApplicationsByPaymentId = $this->getOrderApplicationsByPaymentId($payments);

		// Read the captures once per currency, since one page of payments can span stores
		$applicationsByCurrencyCode = [];
		$currenciesByCode = [];
		foreach ($payments as $payment) {
			$currency = $payment->amount->getCurrency();
			$currenciesByCode[$currency->getCode()] = $currency;
			$applicationsByCurrencyCode[$currency->getCode()] ??= [];
			array_push($applicationsByCurrencyCode[$currency->getCode()], ...($orderApplicationsByPaymentId[(int) $payment->id] ?? []));
		}

		$orderFiguresByApplicationId = [];
		foreach ($applicationsByCurrencyCode as $currencyCode => $applications) {
			$orderFiguresByApplicationId += Plugin::getInstance()->getCreditOrders()->getFiguresByApplicationId($applications, $currenciesByCode[$currencyCode]);
		}

		$figuresByPaymentId = [];
		foreach ($payments as $payment) {
			$currency = $payment->amount->getCurrency();
			$unapplied = $payment->amount->subtract(Amounts::fromSum($lineSumsByPaymentId[$payment->id] ?? null, $currency));
			$refunded = Amounts::zero($currency);

			foreach ($orderApplicationsByPaymentId[(int) $payment->id] ?? [] as $application) {
				$orderFigures = $orderFiguresByApplicationId[(int) $application->id];
				$unapplied = $unapplied->subtract($orderFigures['applied'])->subtract($orderFigures['refunded']);
				$refunded = $refunded->add($orderFigures['refunded']);
			}

			$figuresByPaymentId[(int) $payment->id] = [
				'unapplied' => $unapplied,
				'refunded' => $refunded,
			];
		}

		return $figuresByPaymentId;
	}

	/**
	 * Money received on the account that hasn’t been applied to an invoice line or order.
	 */
	public function getUnappliedByAccount(Account $account): Money
	{
		return Money::sum(
			Amounts::zero($account->getCurrency()),
			...array_map(static fn (array $figures): Money => $figures['unapplied'], array_values($this->getFiguresByPaymentId($this->getPaymentsByAccountId((int) $account->id)))),
		);
	}

	/**
	 * What the account’s payments can be applied to, oldest due first: invoice lines or orders, as the billing setting sets.
	 *
	 * @return AllocationTarget[]
	 */
	public function getOpenTargets(Account $account): array
	{
		$plugin = Plugin::getInstance();
		$buyersById = ArrayHelper::index($plugin->getAccounts()->getBuyersByAccountId((int) $account->id), 'id');

		if ($plugin->getBilling() === Billing::Orders) {
			$creditOrders = $plugin->getCreditOrders();

			return array_map(fn (Order $order): AllocationTarget => new AllocationTarget([
				'id' => (int) $order->id,
				'label' => $creditOrders->getLabel($order),
				'url' => $order->getCpEditUrl(),
				'buyerName' => $this->getBuyerName($buyersById, $creditOrders->getBuyerIdForOrder((int) $order->id)),
				'dateDue' => $creditOrders->getDateDue($order, $account),
				'isOverdue' => $creditOrders->getIsOverdue($order, $account),
				'balance' => $creditOrders->getBalance($order),
			]), $creditOrders->getOpenOrders($account));
		}

		$invoices = $plugin->getInvoices();
		$openLines = $invoices->getOpenLinesByAccountId((int) $account->id);
		$invoicesById = $invoices->getInvoicesByIds(array_values(array_unique(array_map(static fn (InvoiceLine $line): int => (int) $line->invoiceId, $openLines))));
		$figuresByLineId = $invoices->getFiguresByLineId($openLines, $account->getCurrency());
		$now = new DateTime();

		return array_map(function (InvoiceLine $line) use ($invoicesById, $figuresByLineId, $buyersById, $now): AllocationTarget {
			$invoice = $invoicesById[(int) $line->invoiceId];

			return new AllocationTarget([
				'id' => (int) $line->id,
				'label' => (string) $invoice->number,
				'url' => $invoice->getCpEditUrl(),
				'buyerName' => $this->getBuyerName($buyersById, $line->buyerId),
				'dateDue' => $invoice->dateDue,
				'isOverdue' => $invoice->dateDue instanceof DateTime && $invoice->dateDue < $now,
				'balance' => $figuresByLineId[(int) $line->id]['balance'],
			]);
		}, $openLines);
	}

	/**
	 * What each application was applied to, keyed by application ID.
	 *
	 * @param Application[] $applications
	 * @return array<int, AllocationTarget>
	 */
	public function getApplicationTargets(array $applications): array
	{
		$plugin = Plugin::getInstance();
		$targetsByApplicationId = [];

		foreach ($applications as $application) {
			if ($application->orderId !== null) {
				/** @var Commerce $commerce */
				$commerce = Commerce::getInstance();
				$order = $commerce->getOrders()->getOrderById($application->orderId);
				$buyerId = $plugin->getCreditOrders()->getBuyerIdForOrder($application->orderId);
				$buyer = $buyerId !== null ? $plugin->getAccounts()->getBuyerById($buyerId) : null;
				$targetsByApplicationId[(int) $application->id] = new AllocationTarget([
					'id' => $application->orderId,
					'label' => $order instanceof Order ? $plugin->getCreditOrders()->getLabel($order) : '',
					'url' => $order instanceof Order ? $order->getCpEditUrl() : '',
					'buyerName' => $buyer?->getName() ?? Craft::t(Plugin::HANDLE, 'ledger.account'),
				]);

				continue;
			}

			$line = $plugin->getInvoices()->getLineById((int) $application->invoiceLineId);
			if ($line instanceof InvoiceLine) {
				$invoice = $line->getInvoice();
				$targetsByApplicationId[(int) $application->id] = new AllocationTarget([
					'id' => (int) $line->id,
					'label' => (string) $invoice->number,
					'url' => $invoice->getCpEditUrl(),
					'buyerName' => $line->getBuyer()?->getName() ?? Craft::t(Plugin::HANDLE, 'ledger.account'),
				]);
			}
		}

		return $targetsByApplicationId;
	}

	/**
	 * Record money received, applying any of it the caller allocated. Unallocated money stays unapplied.
	 *
	 * The payment and its applications save together or not at all.
	 *
	 * @param array<int, Money> $allocations amounts keyed by invoice line ID in invoice billing, or by order ID in order billing
	 * @throws LedgerException if an allocation doesn’t fit the payment or its line or order, or the account is locked by another request
	 */
	public function recordPayment(Payment $payment, array $allocations = []): bool
	{
		if (! $payment->validate()) {
			return false;
		}

		$allocated = Money::sum(Amounts::zero($payment->amount->getCurrency()), ...array_values($allocations));
		if ($allocated->greaterThan($payment->amount)) {
			throw new LedgerException(Craft::t(Plugin::HANDLE, 'payment.overAllocated', [
				'allocated' => Amounts::toString($allocated),
			]));
		}

		return Plugin::getInstance()->getLedger()->withAccountLock((int) $payment->accountId, function () use ($payment, $allocations): bool {
			$record = new PaymentRecord();
			$record->accountId = (int) $payment->accountId;
			$record->amount = Amounts::toDecimal($payment->amount);
			$record->method = $payment->method->value;
			$record->reference = $payment->reference;
			$record->dateReceived = (string) Db::prepareDateForDb($payment->dateReceived ?? DateTimeHelper::currentUTCDateTime());
			$record->note = $payment->note;
			$record->authorId = $payment->authorId;
			$record->save(false);
			$payment->id = $record->id;

			$this->applyAllocations($payment, $allocations);

			return true;
		});
	}

	/**
	 * Apply part of a payment to an invoice line, reducing what that line’s buyer owes.
	 *
	 * @throws LedgerException if the amount doesn’t fit the payment or the line, or the account is locked by another request
	 */
	public function applyPayment(Payment $payment, InvoiceLine $line, Money $amount): Application
	{
		$plugin = Plugin::getInstance();

		return $plugin->getLedger()->withAccountLock((int) $payment->accountId, function () use ($payment, $line, $amount, $plugin): Application {
			// Load the invoice inside the lock, so a void that ran while this waited is seen
			$invoice = $line->getInvoice();
			if ($invoice->accountId !== $payment->accountId) {
				throw new LedgerException(Craft::t(Plugin::HANDLE, 'application.otherAccount'));
			}

			if ($invoice->getIsVoided()) {
				throw new LedgerException(Craft::t(Plugin::HANDLE, 'application.invoiceVoided'));
			}

			if (! $amount->isPositive()) {
				throw new LedgerException(Craft::t(Plugin::HANDLE, 'application.amountNotPositive'));
			}

			$unapplied = $this->getUnapplied($payment);
			if ($amount->greaterThan($unapplied)) {
				throw new LedgerException(Craft::t(Plugin::HANDLE, 'application.overUnapplied', [
					'unapplied' => Amounts::toString($unapplied),
				]));
			}

			$balance = $plugin->getInvoices()->getLineBalance($line);
			if ($amount->greaterThan($balance)) {
				throw new LedgerException(Craft::t(Plugin::HANDLE, 'application.overBalance', [
					'balance' => Amounts::toString($balance),
				]));
			}

			$record = new ApplicationRecord();
			$record->paymentId = (int) $payment->id;
			$record->invoiceLineId = (int) $line->id;
			$record->amount = Amounts::toDecimal($amount);
			$record->authorId = Craft::$app->getUser()->getIdentity()?->id;
			$record->save(false);

			$application = $this->createApplicationFromRecord($record, $payment->amount->getCurrency());
			$plugin->getLedger()->applyPayment($application, (int) $payment->accountId, $line->buyerId);

			return $application;
		});
	}

	/**
	 * Apply a payment to several invoice lines, all of them or none.
	 *
	 * @param array<int, Money> $amountsByLineId
	 * @throws LedgerException if a line doesn’t exist or an amount doesn’t fit, or the account is locked by another request
	 */
	public function applyToLines(Payment $payment, array $amountsByLineId): void
	{
		Plugin::getInstance()->getLedger()->withAccountLock((int) $payment->accountId, function () use ($payment, $amountsByLineId): void {
			foreach ($amountsByLineId as $lineId => $amount) {
				$line = Plugin::getInstance()->getInvoices()->getLineById($lineId);

				if (! $line instanceof InvoiceLine) {
					throw new LedgerException(Craft::t(Plugin::HANDLE, 'payment.invalidLine'));
				}

				$this->applyPayment($payment, $line, $amount);
			}
		});
	}

	/**
	 * Apply a payment to invoice lines or orders, as the billing setting sets, all of them or none.
	 *
	 * @param array<int, Money> $allocations amounts keyed by invoice line ID in invoice billing, or by order ID in order billing
	 * @throws LedgerException if a line or order doesn’t exist or an amount doesn’t fit, or the account is locked by another request
	 */
	public function applyAllocations(Payment $payment, array $allocations): void
	{
		$plugin = Plugin::getInstance();

		if ($plugin->getBilling() === Billing::Orders) {
			$plugin->getCreditOrders()->applyToOrders($payment, $allocations);

			return;
		}

		$this->applyToLines($payment, $allocations);
	}

	/**
	 * Undo an application, returning its amount to the payment’s unapplied credit.
	 *
	 * @throws LedgerException if the account is locked by another request, or an order application has no amount left to reverse or its order is in the trash
	 */
	public function reverseApplication(Application $application): void
	{
		if ($application->invoiceLineId === null) {
			/** @var Payment $payment */
			$payment = $this->getPaymentById((int) $application->paymentId);
			Plugin::getInstance()->getCreditOrders()->reverseApplication($application, $payment);

			return;
		}

		/** @var InvoiceLine $line */
		$line = Plugin::getInstance()->getInvoices()->getLineById($application->invoiceLineId);
		$accountId = (int) $line->getInvoice()->accountId;

		Plugin::getInstance()->getLedger()->withAccountLock($accountId, function () use ($application, $line, $accountId): void {
			/** @var ApplicationRecord $record */
			$record = ApplicationRecord::findOne($application->id);

			// Skip an application another request already reversed
			if ($record->dateReversed !== null) {
				return;
			}

			$dateReversed = DateTimeHelper::currentUTCDateTime();
			$record->dateReversed = (string) Db::prepareDateForDb($dateReversed);

			$record->save(false);
			$application->dateReversed = $dateReversed;

			Plugin::getInstance()->getLedger()->reverseApplication($application, $accountId, $line->buyerId);
		});
	}

	private function createPaymentFromRecord(PaymentRecord $record): Payment
	{
		$currency = Plugin::getInstance()->getAccounts()->getCurrencyByAccountId($record->accountId);

		return new Payment([
			'id' => $record->id,
			'accountId' => $record->accountId,
			'amount' => Amounts::toMoney($record->amount, $currency),
			'method' => PaymentMethod::from($record->method),
			'reference' => $record->reference,
			'dateReceived' => DateTimeHelper::toDateTime($record->dateReceived) ?: null,
			'note' => $record->note,
			'authorId' => $record->authorId,
			'dateCreated' => DateTimeHelper::toDateTime($record->dateCreated) ?: null,
		]);
	}

	/**
	 * @param Currency|null $currency the payment’s currency, when the caller has it
	 */
	private function createApplicationFromRecord(ApplicationRecord $record, ?Currency $currency = null): Application
	{
		if (! $currency instanceof Currency) {
			/** @var PaymentRecord $paymentRecord */
			$paymentRecord = PaymentRecord::findOne($record->paymentId);
			$currency = Plugin::getInstance()->getAccounts()->getCurrencyByAccountId($paymentRecord->accountId);
		}

		return new Application([
			'id' => $record->id,
			'paymentId' => $record->paymentId,
			'invoiceLineId' => $record->invoiceLineId,
			'orderId' => $record->orderId,
			'transactionId' => $record->transactionId,
			'amount' => Amounts::toMoney($record->amount, $currency),
			'dateReversed' => $record->dateReversed !== null ? (DateTimeHelper::toDateTime($record->dateReversed) ?: null) : null,
			'authorId' => $record->authorId,
			'dateCreated' => DateTimeHelper::toDateTime($record->dateCreated) ?: null,
		]);
	}

	/**
	 * @param array<int|string, Buyer> $buyersById
	 */
	private function getBuyerName(array $buyersById, ?int $buyerId): string
	{
		$buyer = $buyerId !== null ? ($buyersById[$buyerId] ?? null) : null;

		return $buyer instanceof Buyer ? $buyer->getName() : Craft::t(Plugin::HANDLE, 'ledger.account');
	}

	/**
	 * @param Payment[] $payments
	 * @return array<int, Application[]>
	 */
	private function getOrderApplicationsByPaymentId(array $payments): array
	{
		/** @var array<int, Payment> $paymentsById */
		$paymentsById = ArrayHelper::index($payments, 'id');

		/** @var ApplicationRecord[] $records */
		$records = ApplicationRecord::find()
			->where([
				'paymentId' => array_keys($paymentsById),
			])
			->andWhere([
				'not',
				[
					'transactionId' => null,
				],
			])
			->all();

		$applicationsByPaymentId = [];
		foreach ($records as $record) {
			$applicationsByPaymentId[$record->paymentId][] = $this->createApplicationFromRecord($record, $paymentsById[$record->paymentId]->amount->getCurrency());
		}

		return $applicationsByPaymentId;
	}
}
