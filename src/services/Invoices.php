<?php

declare(strict_types=1);

namespace fostercommerce\netterms\services;

use Craft;
use craft\db\Paginator;
use craft\elements\User;
use craft\errors\MutexException;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\Sequence;
use craft\helpers\UrlHelper;
use craft\models\Site;
use craft\web\View;
use DateTime;
use DateTimeZone;
use fostercommerce\netterms\db\Table;
use fostercommerce\netterms\enums\InvoiceStatus;
use fostercommerce\netterms\errors\LedgerException;
use fostercommerce\netterms\events\InvoiceEvent;
use fostercommerce\netterms\helpers\Amounts;
use fostercommerce\netterms\models\Account;
use fostercommerce\netterms\models\Invoice;
use fostercommerce\netterms\models\InvoiceLine;
use fostercommerce\netterms\Plugin;
use fostercommerce\netterms\records\InvoiceLineRecord;
use fostercommerce\netterms\records\InvoiceRecord;
use Money\Currency;
use Money\Money;
use yii\base\Component;

/**
 * Issues, voids and sends invoices, and works out what is owed on them.
 */
class Invoices extends Component
{
	/**
	 * The event that is triggered after an invoice is issued.
	 *
	 * ```php
	 * use fostercommerce\netterms\events\InvoiceEvent;
	 * use fostercommerce\netterms\services\Invoices;
	 * use yii\base\Event;
	 *
	 * Event::on(Invoices::class, Invoices::EVENT_AFTER_ISSUE_INVOICE, function(InvoiceEvent $event) {
	 *     // Create the matching invoice in an accounting system
	 * });
	 * ```
	 */
	public const EVENT_AFTER_ISSUE_INVOICE = 'afterIssueInvoice';

	public function getInvoiceById(int $id): ?Invoice
	{
		$record = InvoiceRecord::findOne($id);

		return $record instanceof InvoiceRecord ? $this->createInvoiceFromRecord($record) : null;
	}

	public function getInvoiceByNumber(string $number): ?Invoice
	{
		$record = InvoiceRecord::findOne([
			'number' => $number,
		]);

		return $record instanceof InvoiceRecord ? $this->createInvoiceFromRecord($record) : null;
	}

	/**
	 * @param int[] $ids
	 * @return array<int, Invoice> keyed by invoice ID
	 */
	public function getInvoicesByIds(array $ids): array
	{
		/** @var InvoiceRecord[] $records */
		$records = InvoiceRecord::find()
			->where([
				'id' => $ids,
			])
			->indexBy('id')
			->all();

		return array_map($this->createInvoiceFromRecord(...), $records);
	}

	/**
	 * @return Invoice[]
	 */
	public function getInvoicesByAccountId(int $accountId): array
	{
		/** @var InvoiceRecord[] $records */
		$records = InvoiceRecord::find()
			->where([
				'accountId' => $accountId,
			])
			->orderBy([
				'dateIssued' => SORT_DESC,
				'id' => SORT_DESC,
			])
			->all();

		return array_map($this->createInvoiceFromRecord(...), $records);
	}

	/**
	 * One page of invoices, newest first, for one account or every account. The page results are `Invoice` models.
	 */
	public function getInvoicePaginator(?int $accountId, int $currentPage, int $pageSize): Paginator
	{
		$paginator = new Paginator(
			InvoiceRecord::find()
				->filterWhere([
					'accountId' => $accountId,
				])
				->orderBy([
					'dateIssued' => SORT_DESC,
					'id' => SORT_DESC,
				]),
			[
				'currentPage' => $currentPage,
				'pageSize' => $pageSize,
			],
		);

		/** @var InvoiceRecord[] $records */
		$records = $paginator->getPageResults();
		$paginator->setPageResults(array_map($this->createInvoiceFromRecord(...), $records));

		return $paginator;
	}

	public function getLineById(int $id): ?InvoiceLine
	{
		$record = InvoiceLineRecord::findOne($id);

		return $record instanceof InvoiceLineRecord ? $this->createLineFromRecord($record) : null;
	}

	/**
	 * @return InvoiceLine[]
	 */
	public function getLinesByInvoiceId(int $invoiceId): array
	{
		/** @var InvoiceLineRecord[] $records */
		$records = InvoiceLineRecord::find()
			->where([
				'invoiceId' => $invoiceId,
			])
			->orderBy([
				'id' => SORT_ASC,
			])
			->all();

		return array_map($this->createLineFromRecord(...), $records);
	}

	/**
	 * Lines on an account’s live invoices with a balance left to pay, oldest due first.
	 *
	 * @return InvoiceLine[]
	 */
	public function getOpenLinesByAccountId(int $accountId): array
	{
		/** @var InvoiceLineRecord[] $records */
		$records = InvoiceLineRecord::find()
			->alias('lines')
			->innerJoin([
				'invoices' => Table::INVOICES,
			], '[[invoices.id]] = [[lines.invoiceId]]')
			->where([
				'invoices.accountId' => $accountId,
				'invoices.dateVoided' => null,
			])
			->orderBy([
				'invoices.dateDue' => SORT_ASC,
				'lines.id' => SORT_ASC,
			])
			->all();

		$lines = array_map($this->createLineFromRecord(...), $records);
		$figuresByLineId = $this->getFiguresByLineId($lines, Plugin::getInstance()->getAccounts()->getCurrencyByAccountId($accountId));

		return array_values(array_filter(
			$lines,
			static fn (InvoiceLine $line): bool => $figuresByLineId[(int) $line->id]['balance']->isPositive(),
		));
	}

	public function getLineAmount(InvoiceLine $line): Money
	{
		return $this->getLineFigures($line)['amount'];
	}

	public function getLineBalance(InvoiceLine $line): Money
	{
		return $this->getLineFigures($line)['balance'];
	}

	/**
	 * Each line's amount, what has been applied to it and its balance, keyed by invoice line ID. The lines share one currency.
	 *
	 * @param InvoiceLine[] $lines
	 * @return array<int, array{amount: Money, paid: Money, balance: Money}>
	 */
	public function getFiguresByLineId(array $lines, Currency $currency): array
	{
		$plugin = Plugin::getInstance();
		$lineIds = array_map(static fn (InvoiceLine $line): int => (int) $line->id, $lines);
		$amountsByLineId = $plugin->getLedger()->getAmountsByInvoiceLineId($lineIds, $currency);
		$paidByLineId = $plugin->getPayments()->getAppliedByInvoiceLineId($lineIds, $currency);
		$zero = Amounts::zero($currency);

		$figuresByLineId = [];
		foreach ($lineIds as $lineId) {
			$amount = $amountsByLineId[$lineId] ?? $zero;
			$paid = $paidByLineId[$lineId] ?? $zero;
			$figuresByLineId[$lineId] = [
				'amount' => $amount,
				'paid' => $paid,
				'balance' => $amount->subtract($paid),
			];
		}

		return $figuresByLineId;
	}

	public function getTotal(Invoice $invoice): Money
	{
		return $this->getInvoiceFigures($invoice)['total'];
	}

	public function getBalance(Invoice $invoice): Money
	{
		return $this->getInvoiceFigures($invoice)['balance'];
	}

	public function getStatus(Invoice $invoice): InvoiceStatus
	{
		return $this->getInvoiceFigures($invoice)['status'];
	}

	/**
	 * Each invoice's total, what has been paid, its balance and its status, keyed by invoice ID.
	 *
	 * @param Invoice[] $invoices
	 * @return array<int, array{total: Money, paid: Money, balance: Money, status: InvoiceStatus}>
	 */
	public function getFiguresByInvoiceId(array $invoices): array
	{
		// Sum each currency separately, since a page of every account's invoices can span stores
		$invoiceGroupsByCurrencyCode = [];
		foreach ($invoices as $invoice) {
			$invoiceGroupsByCurrencyCode[$invoice->getAccount()->getCurrency()->getCode()][] = $invoice;
		}

		$figuresByInvoiceId = [];
		foreach ($invoiceGroupsByCurrencyCode as $currencyCode => $invoiceGroup) {
			$figuresByInvoiceId += $this->calculateFigures($invoiceGroup, new Currency((string) $currencyCode));
		}

		return $figuresByInvoiceId;
	}

	/**
	 * The storefront URL of an invoice on the first site of the account’s store, when the invoice path setting is set.
	 */
	public function getStorefrontUrl(Invoice $invoice): ?string
	{
		$invoicePath = Plugin::getInstance()->getSettings()->invoicePath;

		if ($invoicePath === null) {
			return null;
		}

		/** @var Site|null $site */
		$site = $invoice->getAccount()->getStore()->getSites()->first();

		return UrlHelper::siteUrl(str_replace('{number}', (string) $invoice->number, $invoicePath), null, null, $site?->id);
	}

	/**
	 * Bill every uninvoiced entry made up to the cutoff, one line per buyer. Returns null when no buyer owes a positive amount.
	 *
	 * @throws LedgerException if the account is locked by another request
	 * @throws MutexException if the invoice number sequence is locked by another request
	 */
	public function issueInvoice(Account $account, ?DateTime $cutoff = null): ?Invoice
	{
		$cutoff ??= DateTimeHelper::currentUTCDateTime();
		$ledger = Plugin::getInstance()->getLedger();

		$invoice = $ledger->withAccountLock((int) $account->id, function () use ($account, $cutoff, $ledger): ?Invoice {
			$billableByBuyerId = array_filter(
				$ledger->getUninvoicedByBuyers($account, $cutoff),
				static fn (Money $amount): bool => $amount->isPositive(),
			);

			if ($billableByBuyerId === []) {
				return null;
			}

			$dateIssued = DateTimeHelper::currentUTCDateTime();
			$dateDue = (clone $dateIssued)->modify('+' . $account->getEffectivePaymentTerms() . ' days');

			$invoiceRecord = new InvoiceRecord();
			$invoiceRecord->accountId = (int) $account->id;
			$invoiceRecord->number = 'CL-' . Sequence::next('net-terms:invoice', 6);
			$invoiceRecord->dateIssued = (string) Db::prepareDateForDb($dateIssued);
			$invoiceRecord->dateDue = (string) Db::prepareDateForDb($dateDue);
			$invoiceRecord->save(false);

			foreach (array_keys($billableByBuyerId) as $buyerId) {
				$lineRecord = new InvoiceLineRecord();
				$lineRecord->invoiceId = $invoiceRecord->id;
				$lineRecord->buyerId = $buyerId === 0 ? null : $buyerId;
				$lineRecord->save(false);

				$ledger->assignToInvoiceLine((int) $account->id, $lineRecord->buyerId, $cutoff, $lineRecord->id);
			}

			return $this->createInvoiceFromRecord($invoiceRecord);
		});

		if ($invoice instanceof Invoice && $this->hasEventHandlers(self::EVENT_AFTER_ISSUE_INVOICE)) {
			$this->trigger(self::EVENT_AFTER_ISSUE_INVOICE, new InvoiceEvent([
				'invoice' => $invoice,
			]));
		}

		return $invoice;
	}

	/**
	 * Void a live invoice that has no active payment applications, returning its entries to the next invoice.
	 *
	 * @throws LedgerException if the account is locked by another request
	 */
	public function voidInvoice(Invoice $invoice): bool
	{
		$plugin = Plugin::getInstance();

		return $plugin->getLedger()->withAccountLock((int) $invoice->accountId, function () use ($invoice, $plugin): bool {
			$invoiceRecord = InvoiceRecord::findOne($invoice->id);

			if (! $invoiceRecord instanceof InvoiceRecord || $invoiceRecord->dateVoided !== null) {
				return false;
			}

			foreach ($invoice->getLines() as $invoiceLine) {
				if ($plugin->getPayments()->getActiveApplicationsByLineId((int) $invoiceLine->id, $invoice->getAccount()->getCurrency()) !== []) {
					return false;
				}
			}

			$dateVoided = DateTimeHelper::currentUTCDateTime();
			$invoiceRecord->dateVoided = (string) Db::prepareDateForDb($dateVoided);

			$invoiceRecord->save(false);

			$lineIds = array_map(static fn (InvoiceLine $line): int => (int) $line->id, $invoice->getLines());
			$plugin->getLedger()->unassignFromInvoiceLines($lineIds);
			$invoice->dateVoided = $dateVoided;

			return true;
		});
	}

	/**
	 * Reminders already sent stay sent.
	 */
	public function setPaymentTerms(Invoice $invoice, int $days): bool
	{
		if ($invoice->getIsVoided() || ! $invoice->dateIssued instanceof DateTime) {
			return false;
		}

		// Add the days in UTC, as issuing does, so a daylight saving change doesn't move the due date an hour
		$dateDue = (clone $invoice->dateIssued)->setTimezone(new DateTimeZone('UTC'))->modify("+{$days} days");
		Db::update(Table::INVOICES, [
			'dateDue' => Db::prepareDateForDb($dateDue),
		], [
			'id' => $invoice->id,
		]);
		$invoice->dateDue = $dateDue;

		return true;
	}

	/**
	 * Email the invoice to the account holder.
	 */
	public function sendInvoice(Invoice $invoice): bool
	{
		$account = $invoice->getAccount();
		$holder = $account->getHolder();

		if (! $holder instanceof User || $holder->email === null) {
			Craft::warning(sprintf('Invoice %s was not emailed, because account %d has no holder email address.', $invoice->number, $account->id), Plugin::HANDLE);

			return false;
		}

		$settings = Plugin::getInstance()->getSettings();
		$variables = [
			'invoice' => $invoice,
			'account' => $account,
			'invoiceUrl' => $this->getStorefrontUrl($invoice),
		];

		$view = Craft::$app->getView();
		$body = $settings->invoiceEmailTemplate !== null
			? $view->renderTemplate($settings->invoiceEmailTemplate, $variables, View::TEMPLATE_MODE_SITE)
			: $view->renderTemplate('net-terms/_emails/invoice', $variables, View::TEMPLATE_MODE_CP);

		return Craft::$app->getMailer()->compose()
			->setTo($holder->email)
			->setSubject(Craft::t(Plugin::HANDLE, 'email.invoiceSubject', [
				'number' => $invoice->number,
			]))
			->setHtmlBody($body)
			->send();
	}

	private function createInvoiceFromRecord(InvoiceRecord $record): Invoice
	{
		return new Invoice([
			'id' => $record->id,
			'accountId' => $record->accountId,
			'number' => $record->number,
			'dateIssued' => DateTimeHelper::toDateTime($record->dateIssued) ?: null,
			'dateDue' => DateTimeHelper::toDateTime($record->dateDue) ?: null,
			'dateVoided' => $record->dateVoided !== null ? (DateTimeHelper::toDateTime($record->dateVoided) ?: null) : null,
		]);
	}

	private function createLineFromRecord(InvoiceLineRecord $record): InvoiceLine
	{
		return new InvoiceLine([
			'id' => $record->id,
			'invoiceId' => $record->invoiceId,
			'buyerId' => $record->buyerId,
		]);
	}

	/**
	 * @return array{amount: Money, paid: Money, balance: Money}
	 */
	private function getLineFigures(InvoiceLine $line): array
	{
		return $this->getFiguresByLineId([$line], $line->getInvoice()->getAccount()->getCurrency())[(int) $line->id];
	}

	/**
	 * @return array{total: Money, paid: Money, balance: Money, status: InvoiceStatus}
	 */
	private function getInvoiceFigures(Invoice $invoice): array
	{
		return $this->getFiguresByInvoiceId([$invoice])[(int) $invoice->id];
	}

	private function determineStatus(Invoice $invoice, Money $balance, Money $paid): InvoiceStatus
	{
		if ($invoice->getIsVoided()) {
			return InvoiceStatus::Voided;
		}

		if (! $balance->isPositive()) {
			return InvoiceStatus::Paid;
		}

		return $paid->isPositive() ? InvoiceStatus::PartiallyPaid : InvoiceStatus::Open;
	}

	/**
	 * @param Invoice[] $invoices
	 * @return array<int, array{total: Money, paid: Money, balance: Money, status: InvoiceStatus}>
	 */
	private function calculateFigures(array $invoices, Currency $currency): array
	{
		$plugin = Plugin::getInstance();
		$invoiceIds = array_map(static fn (Invoice $invoice): int => (int) $invoice->id, $invoices);
		$totalsByInvoiceId = $plugin->getLedger()->getAmountsByInvoiceId($invoiceIds, $currency);
		$paidByInvoiceId = $plugin->getPayments()->getAppliedByInvoiceId($invoiceIds, $currency);
		$zero = Amounts::zero($currency);

		$figuresByInvoiceId = [];
		foreach ($invoices as $invoice) {
			$total = $totalsByInvoiceId[(int) $invoice->id] ?? $zero;
			$paid = $paidByInvoiceId[(int) $invoice->id] ?? $zero;
			$balance = $total->subtract($paid);
			$figuresByInvoiceId[(int) $invoice->id] = [
				'total' => $total,
				'paid' => $paid,
				'balance' => $balance,
				'status' => $this->determineStatus($invoice, $balance, $paid),
			];
		}

		return $figuresByInvoiceId;
	}
}
