<?php

declare(strict_types=1);

namespace fostercommerce\netterms\controllers;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\elements\User;
use craft\helpers\ArrayHelper;
use craft\web\Controller;
use craft\web\twig\variables\Paginate;
use fostercommerce\netterms\enums\AccountStatus;
use fostercommerce\netterms\enums\Billing;
use fostercommerce\netterms\enums\SublimitMode;
use fostercommerce\netterms\errors\InvalidAmountException;
use fostercommerce\netterms\errors\LedgerException;
use fostercommerce\netterms\helpers\Amounts;
use fostercommerce\netterms\models\Account;
use fostercommerce\netterms\models\Buyer;
use fostercommerce\netterms\models\Entry;
use fostercommerce\netterms\Plugin;
use Money\Money;
use yii\web\BadRequestHttpException;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

/**
 * Manages accounts and their buyers in the control panel.
 */
class AccountsController extends Controller
{
	use RequestParamsTrait;

	private const LEDGER_PAGE_SIZE = 100;

	public function beforeAction($action): bool
	{
		if (! parent::beforeAction($action)) {
			return false;
		}

		$this->requireCpRequest();
		$this->requirePermission('accessPlugin-' . Plugin::HANDLE);

		if (! in_array($action->id, ['index', 'edit'], true)) {
			$this->requirePermission(Plugin::PERMISSION_MANAGE_ACCOUNTS);
		}

		return true;
	}

	public function actionIndex(): Response
	{
		return $this->renderTemplate('net-terms/accounts/_index', [
			'accounts' => Plugin::getInstance()->getAccounts()->getAllAccounts(),
		]);
	}

	public function actionEdit(?int $accountId = null, ?Account $account = null, string $tab = 'account'): Response
	{
		$plugin = Plugin::getInstance();

		if (! $account instanceof Account) {
			if ($accountId !== null) {
				$account = $plugin->getAccounts()->getAccountById($accountId);
				if (! $account instanceof Account) {
					throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'error.accountNotFound'));
				}
			} else {
				$this->requirePermission(Plugin::PERMISSION_MANAGE_ACCOUNTS);

				/** @var Commerce $commerce */
				$commerce = Commerce::getInstance();
				$account = new Account([
					'storeId' => $commerce->getStores()->getPrimaryStore()?->id,
				]);
			}
		}

		return $this->renderTemplate('net-terms/accounts/_edit', [
			'account' => $account,
			'tab' => $tab,
			'billing' => $plugin->getBilling(),
			'sublimitModeOptions' => SublimitMode::labelMap(),
			'statusOptions' => AccountStatus::labelMap(),
			'defaultSublimitMode' => $plugin->getSettings()->getDefaultSublimitMode(),
			'defaultPaymentTerms' => $plugin->getSettings()->defaultPaymentTerms,
			'canManage' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE_ACCOUNTS),
			'canManagePayments' => Craft::$app->getUser()->checkPermission(Plugin::PERMISSION_MANAGE_PAYMENTS),
			...($account->id !== null ? $this->getTabVariables($account, $tab) : []),
		]);
	}

	public function actionSave(): ?Response
	{
		$this->requirePostRequest();

		$plugin = Plugin::getInstance();
		$accountId = $this->getIdParam('accountId');

		if ($accountId !== null) {
			// Load a fresh copy, so a refused save doesn't leave its values on the account the rest of the request shows
			$account = $plugin->getAccounts()->getFreshAccountById($accountId);
			if (! $account instanceof Account) {
				throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'error.accountNotFound'));
			}
		} else {
			$account = new Account([
				'storeId' => $this->requireIdParam('storeId'),
				'holderId' => $this->getIdParam('holderId'),
			]);
		}

		$sublimitMode = $this->request->getBodyParam('sublimitMode');
		$account->sublimitMode = match (true) {
			$sublimitMode === null, $sublimitMode === '' => null,
			is_string($sublimitMode) => SublimitMode::tryFrom($sublimitMode) ?? throw new BadRequestHttpException('Invalid sublimit mode.'),
			default => throw new BadRequestHttpException('Invalid sublimit mode.'),
		};

		// Refuse terms that aren't whole days, rather than rounding or blanking them
		$paymentTerms = $this->request->getBodyParam('paymentTerms');
		$paymentTermsDays = in_array($paymentTerms, [null, ''], true) ? null : filter_var($paymentTerms, FILTER_VALIDATE_INT, [
			'options' => [
				'min_range' => 0,
			],
		]);
		$account->paymentTerms = $paymentTermsDays === false ? null : $paymentTermsDays;

		$status = $this->request->getBodyParam('status');
		$account->status = (is_string($status) ? AccountStatus::tryFrom($status) : null) ?? throw new BadRequestHttpException('Invalid status.');

		$account->unlimited = (bool) $this->request->getBodyParam('unlimited');

		try {
			$account->creditLimit = $account->unlimited ? null : Amounts::fromPostedInput($this->request->getBodyParam('creditLimit'), $account->getCurrency());
		} catch (InvalidAmountException $invalidAmountException) {
			$account->addError('creditLimit', $invalidAmountException->getMessage());

			return $this->asModelFailure($account, Craft::t(Plugin::HANDLE, 'account.couldNotSave'), 'account');
		}

		if ($paymentTermsDays === false) {
			$account->addError('paymentTerms', Craft::t(Plugin::HANDLE, 'account.paymentTermsInvalid'));

			return $this->asModelFailure($account, Craft::t(Plugin::HANDLE, 'account.couldNotSave'), 'account');
		}

		if (! $plugin->getAccounts()->saveAccount($account)) {
			return $this->asModelFailure($account, Craft::t(Plugin::HANDLE, 'account.couldNotSave'), 'account');
		}

		return $this->asModelSuccess($account, Craft::t(Plugin::HANDLE, 'account.saved'), 'account');
	}

	public function actionDelete(): ?Response
	{
		$this->requirePostRequest();

		$account = $this->requireAccountParam();

		if (! Plugin::getInstance()->getAccounts()->deleteAccount($account)) {
			return $this->asFailure(Craft::t(Plugin::HANDLE, 'account.couldNotDelete'));
		}

		return $this->asSuccess(Craft::t(Plugin::HANDLE, 'account.deleted'), [], 'net-terms/accounts');
	}

	public function actionSaveBuyer(): ?Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$plugin = Plugin::getInstance();
		$account = $this->requireAccountParam();
		$buyerId = $this->getIdParam('buyerId');

		if ($buyerId !== null) {
			$buyer = $this->requireBuyerOnAccount($account, $buyerId);
		} else {
			$buyer = new Buyer([
				'accountId' => $account->id,
				'userId' => $this->getIdParam('userId'),
			]);
		}

		$active = $this->request->getBodyParam('active');
		if ($active !== null) {
			$buyer->active = (bool) $active;
		}

		try {
			$buyer->sublimit = Amounts::fromPostedInput($this->request->getBodyParam('sublimit'), $account->getCurrency());
			$saved = $plugin->getAccounts()->saveBuyer($buyer);
		} catch (InvalidAmountException|LedgerException $userException) {
			return $this->asFailure($userException->getMessage());
		}

		if (! $saved) {
			$errors = implode(' ', $buyer->getFirstErrors());

			return $this->asFailure($errors !== '' ? $errors : Craft::t(Plugin::HANDLE, 'buyer.couldNotSave'));
		}

		return $this->asSuccess(Craft::t(Plugin::HANDLE, 'buyer.saved'));
	}

	public function actionRemoveBuyer(): ?Response
	{
		$this->requirePostRequest();
		$this->requireAcceptsJson();

		$plugin = Plugin::getInstance();
		$account = $this->requireAccountParam();
		$buyer = $this->requireBuyerOnAccount($account, $this->requireIdParam('buyerId'));

		$plugin->getAccounts()->removeBuyer($buyer);

		return $this->asSuccess(Craft::t(Plugin::HANDLE, $buyer->active ? 'buyer.removed' : 'buyer.deactivated'));
	}

	public function actionAdjustmentModal(): Response
	{
		$this->requireInvoiceBilling();

		$account = Plugin::getInstance()->getAccounts()->getAccountById($this->requireQueryIdParam('accountId'));

		if (! $account instanceof Account) {
			throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'error.accountNotFound'));
		}

		return $this->asCpModal()
			->action('net-terms/accounts/adjust')
			->submitButtonLabel(Craft::t(Plugin::HANDLE, 'adjustment.add'))
			->contentTemplate('net-terms/accounts/_adjustment-modal', [
				'account' => $account,
				'buyers' => $account->getBuyers(),
			]);
	}

	public function actionAdjust(): ?Response
	{
		$this->requirePostRequest();
		$this->requireInvoiceBilling();
		$this->requireAcceptsJson();

		$plugin = Plugin::getInstance();
		$account = $this->requireAccountParam();
		$note = $this->getTextParam('note');

		try {
			$amount = Amounts::fromPostedInput($this->request->getBodyParam('amount'), $account->getCurrency());
		} catch (InvalidAmountException $invalidAmountException) {
			return $this->asFailure($invalidAmountException->getMessage());
		}

		if (! $amount instanceof Money || $amount->isZero()) {
			return $this->asFailure(Craft::t(Plugin::HANDLE, 'adjustment.amountRequired'));
		}

		if ($note === '') {
			return $this->asFailure(Craft::t(Plugin::HANDLE, 'adjustment.noteRequired'));
		}

		$buyerId = $this->getIdParam('buyerId');
		$buyer = $buyerId !== null ? $plugin->getAccounts()->getBuyerById($buyerId) : null;

		// Refuse a buyer that doesn't resolve, rather than writing the adjustment to the whole account
		if ($buyerId !== null && (! $buyer instanceof Buyer || $buyer->accountId !== $account->id)) {
			throw new BadRequestHttpException(Craft::t(Plugin::HANDLE, 'error.buyerNotFound'));
		}

		try {
			$plugin->getLedger()->adjust($account, $buyer, $amount, $note);
		} catch (LedgerException $ledgerException) {
			return $this->asFailure($ledgerException->getMessage());
		}

		return $this->asSuccess(Craft::t(Plugin::HANDLE, 'adjustment.saved'));
	}

	/**
	 * Load only what the chosen tab shows, so a busy account's page stays fast.
	 *
	 * @return array<string, mixed>
	 */
	private function getTabVariables(Account $account, string $tab): array
	{
		$plugin = Plugin::getInstance();
		$currentPage = $this->request->getPageNum();

		switch ($tab) {
			case 'buyers':
				$owedByBuyerId = $plugin->getAccounts()->getOwedByBuyers($account);

				return [
					'buyers' => $this->loadBuyerUsers($account->getBuyers()),
					'zero' => Amounts::zero($account->getCurrency()),
					'owedByBuyerId' => $owedByBuyerId,
					'availableByBuyerId' => $plugin->getAccounts()->getAvailableCreditByBuyerId($account, $owedByBuyerId),
					'overSublimitByBuyerId' => $plugin->getAccounts()->getOverSublimitByBuyerId($account, $owedByBuyerId),
				];
			case 'invoices':
				$paginator = $plugin->getInvoices()->getInvoicePaginator((int) $account->id, $currentPage, InvoicesController::PAGE_SIZE);

				return [
					'invoices' => $paginator->getPageResults(),
					'pageInfo' => Paginate::create($paginator),
				];
			case 'orders':
				$paginator = $plugin->getCreditOrders()->getOrderPaginator($account, $currentPage, InvoicesController::PAGE_SIZE);

				return [
					'orders' => $paginator->getPageResults(),
					'pageInfo' => Paginate::create($paginator),
				];
			case 'payments':
				$paginator = $plugin->getPayments()->getPaymentPaginator((int) $account->id, $currentPage, PaymentsController::PAGE_SIZE);

				return [
					'payments' => $paginator->getPageResults(),
					'pageInfo' => Paginate::create($paginator),
				];
			case 'ledger':
				$paginator = $plugin->getLedger()->getEntryPaginator((int) $account->id, $currentPage, self::LEDGER_PAGE_SIZE);
				/** @var Entry[] $entries */
				$entries = $paginator->getPageResults();
				$orderIds = array_values(array_unique(array_filter(array_map(static fn (Entry $entry): ?int => $entry->orderId, $entries))));
				return [
					'entries' => $entries,
					'pageInfo' => Paginate::create($paginator),
					'buyersById' => ArrayHelper::index($this->loadBuyerUsers($account->getBuyers()), 'id'),
					'ordersById' => $orderIds !== [] ? Order::find()->id($orderIds)->indexBy('id')->all() : [],
				];
			default:
				return [];
		}
	}

	/**
	 * Load every buyer's user in one query.
	 *
	 * @param Buyer[] $buyers
	 * @return Buyer[]
	 */
	private function loadBuyerUsers(array $buyers): array
	{
		$userIds = array_map(static fn (Buyer $buyer): ?int => $buyer->userId, $buyers);
		$usersById = $userIds !== [] ? User::find()->id($userIds)->status(null)->indexBy('id')->all() : [];

		foreach ($buyers as $buyer) {
			$buyer->setUser($usersById[(int) $buyer->userId] ?? null);
		}

		return $buyers;
	}

	/**
	 * Order billing has no adjustments, since the orders’ balances are what is owed.
	 */
	private function requireInvoiceBilling(): void
	{
		if (Plugin::getInstance()->getBilling() !== Billing::Invoices) {
			throw new ForbiddenHttpException(Craft::t(Plugin::HANDLE, 'error.invoiceBillingOnly'));
		}
	}
}
