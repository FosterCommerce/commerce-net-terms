<?php

declare(strict_types=1);

namespace fostercommerce\netterms\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\web\Controller;
use craft\web\twig\variables\Paginate;
use fostercommerce\netterms\enums\Billing;
use fostercommerce\netterms\errors\LedgerException;
use fostercommerce\netterms\models\Application;
use fostercommerce\netterms\models\Entry;
use fostercommerce\netterms\models\Invoice;
use fostercommerce\netterms\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Lists, issues, voids and sends invoices, and changes their payment terms, in the control panel.
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

		if (! in_array($action->id, ['index', 'view'], true)) {
			$this->requirePermission(Plugin::PERMISSION_MANAGE_PAYMENTS);
		}

		// Refuse to issue in order billing. Invoices issued before a switch stay viewable.
		if ($action->id === 'issue' && Plugin::getInstance()->getBilling() !== Billing::Invoices) {
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

	public function actionView(int $invoiceId): Response
	{
		$plugin = Plugin::getInstance();
		$invoice = $this->getInvoice($invoiceId);
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

	public function actionIssue(): ?Response
	{
		$this->requirePostRequest();

		$plugin = Plugin::getInstance();
		$account = $this->requireAccountParam();

		try {
			$invoice = $plugin->getInvoices()->issueInvoice($account);
		} catch (LedgerException $ledgerException) {
			return $this->asFailure($ledgerException->getMessage());
		}

		if (! $invoice instanceof Invoice) {
			return $this->asFailure(Craft::t(Plugin::HANDLE, 'invoice.nothingToInvoice'));
		}

		$messageKey = 'invoice.issued';
		if ((bool) $this->request->getBodyParam('send')) {
			$messageKey = $plugin->getInvoices()->sendInvoice($invoice) ? 'invoice.issuedAndSent' : 'invoice.issuedNotSent';
		}

		return $this->asSuccess(Craft::t(Plugin::HANDLE, $messageKey, [
			'number' => $invoice->number,
		]), [
			'invoiceId' => $invoice->id,
		], $invoice->getCpEditUrl());
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

	private function getInvoice(int $invoiceId): Invoice
	{
		$invoice = Plugin::getInstance()->getInvoices()->getInvoiceById($invoiceId);

		if (! $invoice instanceof Invoice) {
			throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'error.invoiceNotFound'));
		}

		return $invoice;
	}
}
