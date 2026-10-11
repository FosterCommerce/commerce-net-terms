<?php

declare(strict_types=1);

namespace fostercommerce\netterms\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\errors\MutexException;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use craft\web\twig\variables\Paginate;
use fostercommerce\netterms\enums\Billing;
use fostercommerce\netterms\errors\LedgerException;
use fostercommerce\netterms\helpers\Amounts;
use fostercommerce\netterms\models\Account;
use fostercommerce\netterms\models\Application;
use fostercommerce\netterms\models\Entry;
use fostercommerce\netterms\models\Invoice;
use fostercommerce\netterms\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Lists, drafts, issues, voids and sends invoices, and changes their payment terms and notes, in the control panel.
 */
class InvoicesController extends Controller
{
	use RequestParamsTrait;

	public const PAGE_SIZE = 50;

	public function beforeAction($action): bool
	{
		if (! parent::beforeAction($action)) {
			return false;
		}

		$this->requireCpRequest();
		$this->requirePermission('accessPlugin-' . Plugin::HANDLE);

		if (! in_array($action->id, ['index', 'view', 'pdf'], true)) {
			$this->requirePermission(Plugin::PERMISSION_MANAGE_PAYMENTS);
		}

		// Refuse new invoices in order billing. Invoices issued before a switch stay viewable.
		if (in_array($action->id, ['edit', 'save'], true) && Plugin::getInstance()->getBilling() !== Billing::Invoices) {
			throw new ForbiddenHttpException(Craft::t(Plugin::HANDLE, 'error.invoiceBillingOnly'));
		}

		return true;
	}

	public function actionIndex(): Response
	{
		$paginator = Plugin::getInstance()->getInvoices()->getInvoicePaginator(null, $this->request->getPageNum(), self::PAGE_SIZE);

		return $this->renderTemplate('net-terms/invoices/_index', [
			'invoices' => $paginator->getPageResults(),
			'pageInfo' => Paginate::create($paginator),
		]);
	}

	public function actionView(int $invoiceId, ?Invoice $invoice = null): Response
	{
		$plugin = Plugin::getInstance();
		$invoice ??= $this->getInvoice($invoiceId);

		if ($invoice->getIsDraft()) {
			return $this->actionEdit($invoiceId, $invoice);
		}

		$currency = $invoice->getAccount()->getCurrency();
		$lines = $invoice->getLines();

		$entriesByLineId = [];
		$applicationsByLineId = [];
		foreach ($lines as $line) {
			$entriesByLineId[(int) $line->id] = $plugin->getLedger()->getEntriesByInvoiceLineId((int) $line->id);
			$applicationsByLineId[(int) $line->id] = $plugin->getPayments()->getActiveApplicationsByLineId((int) $line->id, $currency);
		}

		$orderIds = array_values(array_unique(array_filter(array_map(
			static fn (Entry $entry): ?int => $entry->orderId,
			array_merge(...array_values($entriesByLineId)),
		))));
		$paymentIds = array_values(array_unique(array_map(
			static fn (Application $application): int => (int) $application->paymentId,
			array_merge(...array_values($applicationsByLineId)),
		)));

		return $this->renderTemplate('net-terms/invoices/_view', [
			'invoice' => $invoice,
			'figures' => $plugin->getInvoices()->getFiguresByInvoiceId([$invoice])[$invoiceId],
			'lines' => $lines,
			'figuresByLineId' => $plugin->getInvoices()->getFiguresByLineId($lines, $currency),
			'entriesByLineId' => $entriesByLineId,
			'applicationsByLineId' => $applicationsByLineId,
			'ordersById' => $orderIds !== [] ? Order::find()->id($orderIds)->indexBy('id')->all() : [],
			'paymentsById' => $plugin->getPayments()->getPaymentsByIds($paymentIds),
			'canManagePayments' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE_PAYMENTS),
		]);
	}

	/**
	 * The New invoice page, and the page of a draft. An `accountId` query param presets the account and opens its draft if it has one.
	 */
	public function actionEdit(?int $invoiceId = null, ?Invoice $invoice = null): Response
	{
		$plugin = Plugin::getInstance();
		$accounts = $plugin->getAccounts();
		$invoices = $plugin->getInvoices();

		if (! $invoice instanceof Invoice && $invoiceId !== null) {
			$invoice = $this->getInvoice($invoiceId);
		}

		if (! $invoice instanceof Invoice) {
			$accountId = $this->request->getQueryParam('accountId');
			$account = is_numeric($accountId) ? $accounts->getAccountById((int) $accountId) : null;
			$draft = $account instanceof Account ? $invoices->getDraftByAccountId((int) $account->id) : null;

			if ($draft instanceof Invoice) {
				return $this->redirect($draft->getCpEditUrl());
			}

			$invoice = new Invoice([
				'accountId' => $account?->id,
				'dateDue' => $account instanceof Account ? DateTimeHelper::today()->modify('+' . $account->getEffectivePaymentTerms() . ' days') : null,
			]);
		}

		$account = $invoice->accountId !== null ? $accounts->getAccountById($invoice->accountId) : null;

		$accountOptions = [];
		if ($invoice->id === null) {
			foreach ($accounts->getAllAccounts() as $candidate) {
				$billable = $invoices->getBillableTotal($candidate);
				if ($billable->isPositive() || $candidate->id === $account?->id) {
					$accountOptions[] = [
						'label' => Craft::t(Plugin::HANDLE, 'invoice.accountOption', [
							'name' => $candidate->getHolder()?->getName(),
							'amount' => Amounts::toString($billable),
						]),
						'value' => $candidate->id,
					];
				}
			}
		}

		$billableLines = [];
		$billableByBuyerId = $account instanceof Account ? $invoices->getBillableByBuyerId($account) : [];
		foreach ($billableByBuyerId as $buyerId => $amount) {
			$billableLines[] = [
				'name' => $buyerId !== 0 ? $accounts->getBuyerById($buyerId)?->getName() : Craft::t(Plugin::HANDLE, 'ledger.account'),
				'amount' => $amount,
			];
		}

		$invoiceEmail = $plugin->getEmails()->getInvoiceEmail();

		return $this->renderTemplate('net-terms/invoices/_edit', [
			'invoice' => $invoice,
			'account' => $account,
			'accountOptions' => $accountOptions,
			'billableLines' => $billableLines,
			'billableTotal' => $account instanceof Account ? $invoices->getBillableTotal($account) : null,
			'invoiceNumber' => $invoice->number ?? $invoices->getNextInvoiceNumber(),
			'invoiceEmail' => $invoiceEmail?->enabled ? $invoiceEmail : null,
			'canManagePayments' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE_PAYMENTS),
		]);
	}

	/**
	 * Save a draft, issue it, or issue and email it, as the posted `intent` says: `draft`, `issue` or `send`.
	 */
	public function actionSave(): ?Response
	{
		$this->requirePostRequest();

		$plugin = Plugin::getInstance();
		$invoices = $plugin->getInvoices();
		$invoiceId = $this->getIdParam('invoiceId');
		$invoice = $invoiceId !== null ? $this->getInvoice($invoiceId) : new Invoice([
			'accountId' => $this->getIdParam('accountId'),
		]);

		if (! $invoice->getIsDraft()) {
			throw new BadRequestHttpException(Craft::t(Plugin::HANDLE, 'invoice.alreadyIssued'));
		}

		$dateDue = $this->request->getBodyParam('dateDue');
		$invoice->dateDue = is_array($dateDue) || is_string($dateDue) ? (DateTimeHelper::toDateTime($dateDue) ?: null) : null;

		$invoice->notes = $this->getOptionalTextParam('notes');
		$intent = $this->request->getBodyParam('intent');

		try {
			if ($intent === 'draft') {
				if (! $invoices->saveDraft($invoice)) {
					return $this->asModelFailure($invoice, Craft::t(Plugin::HANDLE, 'invoice.couldNotSaveDraft'), 'invoice');
				}

				return $this->asModelSuccess($invoice, Craft::t(Plugin::HANDLE, 'invoice.draftSaved'), 'invoice', [], $invoice->getCpEditUrl());
			}

			$issuedInvoice = $invoice->validate() ? $invoices->issueInvoice($invoice->getAccount(), null, $invoice) : null;
		} catch (LedgerException $ledgerException) {
			return $this->asFailure($ledgerException->getMessage());
		} catch (MutexException) {
			return $this->asFailure(Craft::t(Plugin::HANDLE, 'invoice.numberBusy'));
		}

		if (! $issuedInvoice instanceof Invoice) {
			if (! $invoice->hasErrors()) {
				$invoice->addError('accountId', Craft::t(Plugin::HANDLE, 'invoice.nothingToInvoice'));
			}

			return $this->asModelFailure($invoice, Craft::t(Plugin::HANDLE, 'invoice.couldNotIssue'), 'invoice');
		}

		$messageKey = 'invoice.issued';
		if ($intent === 'send') {
			$messageKey = $invoices->sendInvoice($issuedInvoice) ? 'invoice.issuedAndSent' : 'invoice.issuedNotSent';
		}

		return $this->asSuccess(Craft::t(Plugin::HANDLE, $messageKey, [
			'number' => $issuedInvoice->number,
		]), [
			'invoiceId' => $issuedInvoice->id,
		], $issuedInvoice->getCpEditUrl());
	}

	public function actionDeleteDraft(): ?Response
	{
		$this->requirePostRequest();

		$invoice = $this->getInvoice($this->requireIdParam('invoiceId'));

		if (! Plugin::getInstance()->getInvoices()->deleteDraft($invoice)) {
			return $this->asFailure(Craft::t(Plugin::HANDLE, 'invoice.couldNotDeleteDraft'));
		}

		return $this->asSuccess(Craft::t(Plugin::HANDLE, 'invoice.draftDeleted'), [], 'net-terms/invoices');
	}

	public function actionNotesModal(): Response
	{
		$invoice = $this->getInvoice($this->requireQueryIdParam('invoiceId'));

		return $this->asCpModal()
			->action('net-terms/invoices/save-notes')
			->submitButtonLabel(Craft::t(Plugin::HANDLE, 'invoice.saveNotes'))
			->contentTemplate('net-terms/invoices/_notes-modal', [
				'invoice' => $invoice,
			]);
	}

	public function actionSaveNotes(): ?Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$invoice = $this->getInvoice($this->requireIdParam('invoiceId'));
		Plugin::getInstance()->getInvoices()->saveNotes($invoice, $this->getOptionalTextParam('notes'));

		return $this->asSuccess(Craft::t(Plugin::HANDLE, 'invoice.notesSaved'));
	}

	public function actionVoid(): ?Response
	{
		$this->requirePostRequest();

		$invoice = $this->getInvoice($this->requireIdParam('invoiceId'));

		if (! Plugin::getInstance()->getInvoices()->voidInvoice($invoice)) {
			return $this->asFailure(Craft::t(Plugin::HANDLE, 'invoice.couldNotVoid'));
		}

		return $this->asSuccess(Craft::t(Plugin::HANDLE, 'invoice.voided'));
	}

	public function actionTermsModal(): Response
	{
		$invoice = $this->getInvoice($this->requireQueryIdParam('invoiceId'));

		return $this->asCpModal()
			->action('net-terms/invoices/save-terms')
			->submitButtonLabel(Craft::t(Plugin::HANDLE, 'invoice.saveTerms'))
			->contentTemplate('net-terms/invoices/_terms-modal', [
				'invoice' => $invoice,
			]);
	}

	public function actionSaveTerms(): ?Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$invoice = $this->getInvoice($this->requireIdParam('invoiceId'));
		$days = filter_var($this->request->getBodyParam('paymentTerms'), FILTER_VALIDATE_INT, [
			'options' => [
				'min_range' => 0,
			],
		]);

		if ($days === false) {
			return $this->asFailure(Craft::t(Plugin::HANDLE, 'invoice.termsInvalid'));
		}

		if (! Plugin::getInstance()->getInvoices()->setPaymentTerms($invoice, $days)) {
			return $this->asFailure(Craft::t(Plugin::HANDLE, 'invoice.termsVoided'));
		}

		return $this->asSuccess(Craft::t(Plugin::HANDLE, 'invoice.termsSaved'));
	}

	public function actionSend(): ?Response
	{
		$this->requirePostRequest();

		$invoice = $this->getInvoice($this->requireIdParam('invoiceId'));

		if (! Plugin::getInstance()->getInvoices()->sendInvoice($invoice)) {
			return $this->asFailure(Craft::t(Plugin::HANDLE, 'invoice.couldNotSend'));
		}

		return $this->asSuccess(Craft::t(Plugin::HANDLE, 'invoice.sent'));
	}

	public function actionPdf(): Response
	{
		$invoice = $this->getInvoice($this->requireQueryIdParam('invoiceId'));
		$pdf = Plugin::getInstance()->getInvoices()->renderPdf($invoice);

		return $this->response->sendContentAsFile($pdf['content'], $pdf['fileName'], [
			'inline' => true,
			'mimeType' => 'application/pdf',
		]);
	}

	private function getInvoice(int $invoiceId): Invoice
	{
		$invoice = Plugin::getInstance()->getInvoices()->getInvoiceById($invoiceId);

		if (! $invoice instanceof Invoice) {
			throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'error.invoiceNotFound'));
		}

		return $invoice;
	}
}
