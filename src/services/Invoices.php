<?php

declare(strict_types=1);

namespace fostercommerce\netterms\services;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\db\Paginator;
use craft\errors\MutexException;
use craft\helpers\DateTimeHelper;
use craft\helpers\Db;
use craft\helpers\FileHelper;
use craft\helpers\Sequence;
use craft\helpers\UrlHelper;
use craft\models\Site;
use craft\web\View;
use DateTime;
use DateTimeZone;
use Dompdf\Dompdf;
use Dompdf\Options;
use fostercommerce\netterms\db\Table;
use fostercommerce\netterms\enums\InvoiceStatus;
use fostercommerce\netterms\errors\LedgerException;
use fostercommerce\netterms\events\InvoiceEvent;
use fostercommerce\netterms\helpers\Amounts;
use fostercommerce\netterms\models\Account;
use fostercommerce\netterms\models\Email;
use fostercommerce\netterms\models\Entry;
use fostercommerce\netterms\models\Invoice;
use fostercommerce\netterms\models\InvoiceLine;
use fostercommerce\netterms\Plugin;
use fostercommerce\netterms\records\InvoiceLineRecord;
use fostercommerce\netterms\records\InvoiceRecord;
use Money\Currency;
use Money\Money;
use yii\base\Component;
use yii\db\Expression;

/**
 * Saves drafts, issues, voids and sends invoices, and works out their balances.
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

	private const NUMBER_SEQUENCE = 'net-terms:invoice';

	public function getInvoiceById(int $id): ?Invoice
	{
		$record = InvoiceRecord::findOne($id);

		return $record instanceof InvoiceRecord ? $this->createInvoiceFromRecord($record) : null;
	}

	/**
	 * An issued invoice by its number. Drafts are left out, since `craft.netTerms` and the storefront PDF action look invoices up by number.
	 */
	public function getInvoiceByNumber(string $number): ?Invoice
	{
		$record = InvoiceRecord::find()
			->where([
				'number' => $number,
			])
			->andWhere(['not', [
				'dateIssued' => null,
			]])
			->one();

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
	 * An account's issued invoices, newest first. Drafts are left out, since `craft.netTerms` returns these to templates.
	 *
	 * @return Invoice[]
	 */
	public function getInvoicesByAccountId(int $accountId): array
	{
		/** @var InvoiceRecord[] $records */
		$records = InvoiceRecord::find()
			->where([
				'accountId' => $accountId,
			])
			->andWhere(['not', [
				'dateIssued' => null,
			]])
			->orderBy([
				'dateIssued' => SORT_DESC,
				'id' => SORT_DESC,
			])
			->all();

		return array_map($this->createInvoiceFromRecord(...), $records);
	}

	/**
	 * One page of invoices, drafts then newest first, for one account or every account. The page results are `Invoice` models.
	 */
	public function getInvoicePaginator(?int $accountId, int $currentPage, int $pageSize): Paginator
	{
		$paginator = new Paginator(
			InvoiceRecord::find()
				->filterWhere([
					'accountId' => $accountId,
				])
				->orderBy([
					new Expression('[[dateIssued]] IS NULL DESC'),
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

	public function getDraftByAccountId(int $accountId): ?Invoice
	{
		$record = InvoiceRecord::findOne([
			'accountId' => $accountId,
			'dateIssued' => null,
		]);

		return $record instanceof InvoiceRecord ? $this->createInvoiceFromRecord($record) : null;
	}

	/**
	 * The invoice number the next new invoice or draft will get.
	 */
	public function getNextInvoiceNumber(): string
	{
		return $this->formatInvoiceNumber((int) Sequence::current(self::NUMBER_SEQUENCE) + 1);
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
	 * Lines on an account’s unvoided invoices with a balance left to pay, oldest due first.
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
	 * What issuing an invoice now would bill each buyer with a positive balance. Buyer ID 0 is the account itself.
	 *
	 * @return array<int, Money>
	 */
	public function getBillableByBuyerId(Account $account, ?DateTime $cutoff = null): array
	{
		return array_filter(
			Plugin::getInstance()->getLedger()->getUninvoicedByBuyers($account, $cutoff ?? DateTimeHelper::currentUTCDateTime()),
			static fn (Money $amount): bool => $amount->isPositive(),
		);
	}

	public function getBillableTotal(Account $account): Money
	{
		return Money::sum(Amounts::zero($account->getCurrency()), ...array_values($this->getBillableByBuyerId($account)));
	}

	/**
	 * Save a draft, giving a new one the next invoice number. An account has one draft at a time.
	 *
	 * @throws LedgerException if the account is locked by another request
	 * @throws MutexException if the invoice number sequence is locked by another request
	 */
	public function saveDraft(Invoice $invoice): bool
	{
		if (! $invoice->validate()) {
			return false;
		}

		return Plugin::getInstance()->getLedger()->withAccountLock((int) $invoice->accountId, function () use ($invoice): bool {
			$existingDraft = $this->getDraftByAccountId((int) $invoice->accountId);
			if ($existingDraft instanceof Invoice && $existingDraft->id !== $invoice->id) {
				$invoice->addError('accountId', Craft::t(Plugin::HANDLE, 'invoice.draftExists', [
					'number' => $existingDraft->number,
				]));

				return false;
			}

			$invoiceRecord = $invoice->id !== null ? InvoiceRecord::findOne($invoice->id) : new InvoiceRecord();

			// Refuse when a concurrent request issued this draft first
			if (! $invoiceRecord instanceof InvoiceRecord || $invoiceRecord->dateIssued !== null) {
				return false;
			}

			$invoiceRecord->accountId = (int) $invoice->accountId;
			$invoiceRecord->number ??= $this->formatInvoiceNumber((int) Sequence::next(self::NUMBER_SEQUENCE));
			$invoiceRecord->dateDue = (string) Db::prepareDateForDb($invoice->dateDue);
			$invoiceRecord->notes = $invoice->notes;
			$invoiceRecord->save(false);

			$invoice->id = $invoiceRecord->id;
			$invoice->number = $invoiceRecord->number;

			return true;
		});
	}

	/**
	 * Delete a draft. Its number isn't reused.
	 */
	public function deleteDraft(Invoice $invoice): bool
	{
		return InvoiceRecord::deleteAll([
			'id' => $invoice->id,
			'dateIssued' => null,
		]) > 0;
	}

	public function saveNotes(Invoice $invoice, ?string $notes): void
	{
		Db::update(Table::INVOICES, [
			'notes' => $notes,
		], [
			'id' => $invoice->id,
		]);
		$invoice->notes = $notes;
	}

	/**
	 * Bill every uninvoiced entry made up to the cutoff, one line per buyer. Returns null when no buyer has a positive balance.
	 *
	 * Pass a draft, or a new invoice, to issue it with its due date and notes. Otherwise the due date follows the account's payment terms.
	 *
	 * @throws LedgerException if the account is locked by another request
	 * @throws MutexException if the invoice number sequence is locked by another request
	 */
	public function issueInvoice(Account $account, ?DateTime $cutoff = null, ?Invoice $invoice = null): ?Invoice
	{
		$cutoff ??= DateTimeHelper::currentUTCDateTime();
		$ledger = Plugin::getInstance()->getLedger();

		$issuedInvoice = $ledger->withAccountLock((int) $account->id, function () use ($account, $cutoff, $ledger, $invoice): ?Invoice {
			$billableByBuyerId = $this->getBillableByBuyerId($account, $cutoff);
			$invoiceRecord = $invoice?->id !== null ? InvoiceRecord::findOne($invoice->id) : new InvoiceRecord();

			// Return null when a concurrent request issued this draft first
			if ($billableByBuyerId === [] || ! $invoiceRecord instanceof InvoiceRecord || $invoiceRecord->dateIssued !== null) {
				return null;
			}

			// Refuse a new invoice while the account has a draft, since issuing would bill the charges the draft is for
			if ($invoice instanceof Invoice) {
				$draft = $this->getDraftByAccountId((int) $account->id);
				if ($draft instanceof Invoice && $draft->id !== $invoice->id) {
					$invoice->addError('accountId', Craft::t(Plugin::HANDLE, 'invoice.draftExists', [
						'number' => $draft->number,
					]));

					return null;
				}
			}

			$paymentTerms = $invoice?->dateDue instanceof DateTime ? $this->getDaysUntil($invoice->dateDue) : $account->getEffectivePaymentTerms();
			$dateIssued = DateTimeHelper::currentUTCDateTime();
			$dateDue = (clone $dateIssued)->modify('+' . $paymentTerms . ' days');

			$invoiceRecord->accountId = (int) $account->id;
			$invoiceRecord->number ??= $this->formatInvoiceNumber((int) Sequence::next(self::NUMBER_SEQUENCE));
			$invoiceRecord->dateIssued = (string) Db::prepareDateForDb($dateIssued);
			$invoiceRecord->dateDue = (string) Db::prepareDateForDb($dateDue);
			$invoiceRecord->notes = $invoice?->notes;
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

		if ($issuedInvoice instanceof Invoice && $this->hasEventHandlers(self::EVENT_AFTER_ISSUE_INVOICE)) {
			$this->trigger(self::EVENT_AFTER_ISSUE_INVOICE, new InvoiceEvent([
				'invoice' => $issuedInvoice,
			]));
		}

		return $issuedInvoice;
	}

	/**
	 * Void an invoice that has no active payment applications, unassigning its entries so the next invoice bills them.
	 *
	 * @throws LedgerException if the account is locked by another request
	 */
	public function voidInvoice(Invoice $invoice): bool
	{
		$plugin = Plugin::getInstance();

		return $plugin->getLedger()->withAccountLock((int) $invoice->accountId, function () use ($invoice, $plugin): bool {
			$invoiceRecord = InvoiceRecord::findOne($invoice->id);

			if (! $invoiceRecord instanceof InvoiceRecord || $invoiceRecord->dateIssued === null || $invoiceRecord->dateVoided !== null) {
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
	 * Change an unvoided invoice’s payment terms and due date. Reminders already sent stay sent.
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
	 * Email the invoice with the invoice email. Returns false when that email is turned off or can't be sent.
	 */
	public function sendInvoice(Invoice $invoice): bool
	{
		$email = Plugin::getInstance()->getEmails()->getInvoiceEmail();

		return ! $invoice->getIsDraft() && $email instanceof Email && $email->enabled && Plugin::getInstance()->getEmails()->send($email, $invoice);
	}

	/**
	 * Render the invoice PDF, named after the invoice number.
	 *
	 * @return array{content: string, fileName: string}
	 */
	public function renderPdf(Invoice $invoice): array
	{
		$settings = Plugin::getInstance()->getSettings();
		$templatePath = $settings->invoicePdfTemplate;
		$view = Craft::$app->getView();
		$ledger = Plugin::getInstance()->getLedger();
		$lines = $invoice->getLines();

		$entriesByLineId = [];
		foreach ($lines as $line) {
			$entriesByLineId[(int) $line->id] = $ledger->getEntriesByInvoiceLineId((int) $line->id);
		}

		$html = $view->renderTemplate($templatePath ?? 'net-terms/_pdfs/invoice', [
			'invoice' => $invoice,
			'account' => $invoice->getAccount(),
			'figures' => $this->getFiguresByInvoiceId([$invoice])[(int) $invoice->id],
			'lines' => $lines,
			'figuresByLineId' => $this->getFiguresByLineId($lines, $invoice->getAccount()->getCurrency()),
			'entriesByLineId' => $entriesByLineId,
			'ordersById' => Order::find()
				->id(array_filter(array_map(static fn (Entry $entry): ?int => $entry->orderId, array_merge(...array_values($entriesByLineId)))))
				->trashed(null)
				->indexBy('id')
				->all(),
			'invoiceUrl' => $this->getStorefrontUrl($invoice),
		], $templatePath !== null ? View::TEMPLATE_MODE_SITE : View::TEMPLATE_MODE_CP);

		$dompdf = new Dompdf($this->getDompdfOptions($settings->invoicePdfPaperSize, $settings->invoicePdfOrientation));
		$dompdf->loadHtml($html);
		$dompdf->render();

		return [
			'content' => (string) $dompdf->output(),
			'fileName' => $invoice->number . '.pdf',
		];
	}

	private function createInvoiceFromRecord(InvoiceRecord $record): Invoice
	{
		return new Invoice([
			'id' => $record->id,
			'accountId' => $record->accountId,
			'number' => $record->number,
			'dateIssued' => $record->dateIssued !== null ? (DateTimeHelper::toDateTime($record->dateIssued) ?: null) : null,
			'dateDue' => DateTimeHelper::toDateTime($record->dateDue) ?: null,
			'dateVoided' => $record->dateVoided !== null ? (DateTimeHelper::toDateTime($record->dateVoided) ?: null) : null,
			'notes' => $record->notes,
		]);
	}

	private function formatInvoiceNumber(int $sequenceNumber): string
	{
		$settings = Plugin::getInstance()->getSettings();

		return $settings->getInvoiceNumberPrefix() . str_pad((string) ($sequenceNumber + $settings->invoiceNumberStart - 1), 6, '0', STR_PAD_LEFT);
	}

	/**
	 * Whole days from today to a date, both in the system time zone, so a due date picked in the control panel keeps its day.
	 */
	private function getDaysUntil(DateTime $date): int
	{
		$timeZone = new DateTimeZone(Craft::$app->getTimeZone());

		return (int) DateTimeHelper::today($timeZone)->diff((clone $date)->setTimezone($timeZone))->days;
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
		if ($invoice->getIsDraft()) {
			return InvoiceStatus::Draft;
		}

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

	/**
	 * Dompdf options for the invoice PDF, matching Commerce's for order PDFs.
	 */
	private function getDompdfOptions(string $paperSize, string $paperOrientation): Options
	{
		$pathService = Craft::$app->getPath();
		$tempDir = $pathService->getTempPath() . DIRECTORY_SEPARATOR . 'net_terms_dompdf';
		$fontCache = $pathService->getCachePath() . DIRECTORY_SEPARATOR . 'net_terms_dompdf';
		FileHelper::createDirectory($tempDir);
		FileHelper::createDirectory($fontCache);

		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();

		$options = new Options();
		$options->setTempDir($tempDir);
		$options->setFontCache($fontCache);
		$options->setIsRemoteEnabled($commerce->getSettings()->pdfAllowRemoteImages);
		$options->setDefaultPaperSize($paperSize);
		$options->setDefaultPaperOrientation($paperOrientation);

		return $options;
	}
}
