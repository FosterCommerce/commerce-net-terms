<?php

declare(strict_types=1);

namespace fostercommerce\netterms\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\elements\User;
use craft\helpers\DateTimeHelper;
use craft\web\Controller;
use craft\web\twig\variables\Paginate;
use fostercommerce\netterms\enums\PaymentMethod;
use fostercommerce\netterms\errors\InvalidAmountException;
use fostercommerce\netterms\errors\LedgerException;
use fostercommerce\netterms\helpers\Amounts;
use fostercommerce\netterms\models\Account;
use fostercommerce\netterms\models\AllocationTarget;
use fostercommerce\netterms\models\Application;
use fostercommerce\netterms\models\Payment;
use fostercommerce\netterms\Plugin;
use Money\Currency;
use Money\Money;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Records payments and applies them to invoice lines or orders in the control panel.
 */
class PaymentsController extends Controller
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

		return true;
	}

	public function actionIndex(): Response
	{
		$paginator = Plugin::getInstance()->getPayments()->getPaymentPaginator(null, $this->request->getPageNum(), self::PAGE_SIZE);

		return $this->renderTemplate('net-terms/payments/_index', [
			'payments' => $paginator->getPageResults(),
			'pageInfo' => Paginate::create($paginator),
		]);
	}

	public function actionEdit(?Payment $payment = null): Response
	{
		if (! $payment instanceof Payment) {
			$accountId = $this->request->getQueryParam('accountId');
			$account = is_numeric($accountId) ? Plugin::getInstance()->getAccounts()->getAccountById((int) $accountId) : null;
			/** @var Commerce $commerce */
			$commerce = Commerce::getInstance();
			/** @var Currency $currency */
			$currency = $account instanceof Account ? $account->getCurrency() : $commerce->getStores()->getPrimaryStore()?->getCurrency();

			$payment = new Payment([
				'accountId' => $account?->id,
				'amount' => Amounts::zero($currency),
				'dateReceived' => DateTimeHelper::now(),
			]);
		}

		$accountOptions = [];
		foreach (Plugin::getInstance()->getAccounts()->getAllAccounts() as $account) {
			/** @var User $holder */
			$holder = $account->getHolder();
			$accountOptions[] = [
				'label' => $holder->getName(),
				'value' => $account->id,
			];
		}

		return $this->renderTemplate('net-terms/payments/_edit', [
			'payment' => $payment,
			'accountOptions' => $accountOptions,
			'methodOptions' => PaymentMethod::labelMap(),
			...$this->getAllocationVariables($payment->accountId),
		]);
	}

	public function actionSave(): ?Response
	{
		$this->requirePostRequest();

		$plugin = Plugin::getInstance();
		$account = $this->requireAccountParam();

		$method = $this->request->getBodyParam('method');
		$dateReceived = $this->request->getBodyParam('dateReceived');

		$reference = $this->getTextParam('reference');
		$note = $this->getTextParam('note');

		$payment = new Payment([
			'accountId' => $account->id,
			'amount' => Amounts::zero($account->getCurrency()),
			'method' => (is_string($method) ? PaymentMethod::tryFrom($method) : null) ?? PaymentMethod::Other,
			'reference' => $reference !== '' ? $reference : null,
			'dateReceived' => is_string($dateReceived) || is_array($dateReceived) ? (DateTimeHelper::toDateTime($dateReceived) ?: null) : null,
			'note' => $note !== '' ? $note : null,
			'authorId' => Craft::$app->getUser()->getIdentity()?->id,
		]);

		try {
			$payment->amount = Amounts::fromPostedInput($this->request->getBodyParam('amount'), $account->getCurrency()) ?? $payment->amount;
			$recorded = $plugin->getPayments()->recordPayment($payment, $this->getAllocationsParam($account));
		} catch (InvalidAmountException|LedgerException $userException) {
			$payment->addError('amount', $userException->getMessage());
			$recorded = false;
		}

		if (! $recorded) {
			return $this->asModelFailure($payment, Craft::t(Plugin::HANDLE, 'payment.couldNotSave'), 'payment');
		}

		return $this->asModelSuccess($payment, Craft::t(Plugin::HANDLE, 'payment.saved'), 'payment', [], 'net-terms/payments/' . $payment->id);
	}

	public function actionView(int $paymentId): Response
	{
		$plugin = Plugin::getInstance();
		$payment = $this->getPayment($paymentId);
		$applications = $plugin->getPayments()->getApplicationsByPaymentId($paymentId, $payment->amount->getCurrency());

		return $this->renderTemplate('net-terms/payments/_view', [
			'payment' => $payment,
			'account' => $plugin->getAccounts()->getAccountById((int) $payment->accountId),
			'applications' => $applications,
			'applicationTargets' => $plugin->getPayments()->getApplicationTargets($applications),
			'orderApplicationFigures' => $plugin->getCreditOrders()->getFiguresByApplicationId($applications, $payment->amount->getCurrency()),
			'refunded' => $plugin->getPayments()->getRefunded($payment),
			'unapplied' => $plugin->getPayments()->getUnapplied($payment),
			...$this->getAllocationVariables($payment->accountId),
			'canManagePayments' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE_PAYMENTS),
		]);
	}

	public function actionApply(): ?Response
	{
		$this->requirePostRequest();

		$plugin = Plugin::getInstance();
		$payment = $this->getPayment($this->requireIdParam('paymentId'));
		/** @var Account $account */
		$account = $plugin->getAccounts()->getAccountById((int) $payment->accountId);

		try {
			$allocations = $this->getAllocationsParam($account);

			if ($allocations === []) {
				return $this->asFailure(Craft::t(Plugin::HANDLE, 'application.noneEntered'));
			}

			$plugin->getPayments()->applyAllocations($payment, $allocations);
		} catch (InvalidAmountException|LedgerException $userException) {
			return $this->asFailure($userException->getMessage());
		}

		return $this->asSuccess(Craft::t(Plugin::HANDLE, 'application.applied'), [], 'net-terms/payments/' . $payment->id);
	}

	public function actionReverse(): ?Response
	{
		$this->requirePostRequest();

		$application = Plugin::getInstance()->getPayments()->getApplicationById($this->requireIdParam('applicationId'));

		if (! $application instanceof Application) {
			throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'error.applicationNotFound'));
		}

		try {
			Plugin::getInstance()->getPayments()->reverseApplication($application);
		} catch (LedgerException $ledgerException) {
			return $this->asFailure($ledgerException->getMessage());
		}

		return $this->asSuccess(Craft::t(Plugin::HANDLE, 'application.reversed'));
	}

	/**
	 * Read the posted amount for each invoice line or order, keeping only positive amounts.
	 *
	 * @return array<int, Money>
	 * @throws InvalidAmountException if an amount isn’t a number
	 */
	private function getAllocationsParam(Account $account): array
	{
		$amountInputs = $this->request->getBodyParam('amounts');
		$allocations = [];

		foreach (is_array($amountInputs) ? $amountInputs : [] as $targetId => $amountInput) {
			$amount = Amounts::fromPostedInput($amountInput, $account->getCurrency());

			if ($amount instanceof Money && $amount->isPositive()) {
				$allocations[(int) $targetId] = $amount;
			}
		}

		return $allocations;
	}

	private function getPayment(int $paymentId): Payment
	{
		$payment = Plugin::getInstance()->getPayments()->getPaymentById($paymentId);

		if (! $payment instanceof Payment) {
			throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'error.paymentNotFound'));
		}

		return $payment;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function getAllocationVariables(?int $accountId): array
	{
		$plugin = Plugin::getInstance();
		$account = $accountId !== null ? $plugin->getAccounts()->getAccountById($accountId) : null;

		if (! $account instanceof Account) {
			return [
				'billing' => $plugin->getBilling(),
				'openTargets' => [],
			];
		}

		$openTargets = $plugin->getPayments()->getOpenTargets($account);
		$zero = Amounts::zero($account->getCurrency());
		$openTotal = Money::sum($zero, ...array_map(static fn (AllocationTarget $target): Money => $target->balance ?? $zero, $openTargets));

		return [
			'billing' => $plugin->getBilling(),
			'openTargets' => $openTargets,
			'openTotal' => $openTotal,
			'openTotalDecimal' => Amounts::toDecimal($openTotal),
		];
	}
}
