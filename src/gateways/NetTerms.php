<?php

declare(strict_types=1);

namespace fostercommerce\netterms\gateways;

use Craft;
use craft\commerce\base\Gateway;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\elements\Order;
use craft\commerce\errors\NotImplementedException;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\PaymentSource;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\web\Response as WebResponse;
use craft\web\View;
use fostercommerce\netterms\enums\AccountStatus;
use fostercommerce\netterms\enums\Billing;
use fostercommerce\netterms\errors\LedgerException;
use fostercommerce\netterms\helpers\Amounts;
use fostercommerce\netterms\models\Account;
use fostercommerce\netterms\models\Buyer;
use fostercommerce\netterms\models\Entry;
use fostercommerce\netterms\models\payments\NetTermsPaymentForm;
use fostercommerce\netterms\models\responses\NetTermsResponse;
use fostercommerce\netterms\Plugin;
use InvalidArgumentException;
use Money\Money;

/**
 * Charges an order to the buyer’s account.
 */
class NetTerms extends Gateway
{
	public static function displayName(): string
	{
		return Craft::t(Plugin::HANDLE, 'gateway.displayName');
	}

	/**
	 * Purchase bills on invoices. Authorize Only leaves each order unpaid, to bill by order.
	 *
	 * @return array<string, string>
	 */
	public function getPaymentTypeOptions(): array
	{
		return [
			TransactionRecord::TYPE_PURCHASE => Craft::t(Plugin::HANDLE, 'gateway.paymentTypeInvoices'),
			TransactionRecord::TYPE_AUTHORIZE => Craft::t(Plugin::HANDLE, 'gateway.paymentTypeOrders'),
		];
	}

	/**
	 * Refuse a change of payment type while any account is unsettled, because each billing mode counts an account’s balance from different records.
	 */
	public function validatePaymentType(string $attribute): void
	{
		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();

		// Refuse a second Net Terms gateway with a different payment type, since the payment type is the billing mode for every account
		foreach ($commerce->getGateways()->getAllGateways() as $gateway) {
			if ($gateway instanceof self && $gateway->id !== $this->id && $gateway->paymentType !== $this->paymentType) {
				$this->addError($attribute, Craft::t(Plugin::HANDLE, 'gateway.paymentTypeMismatch', [
					'gateway' => $gateway->name,
				]));

				return;
			}
		}

		// Compare with the billing mode in force, so archiving the gateway and adding another can't skip the check
		$newBilling = $this->paymentType === TransactionRecord::TYPE_AUTHORIZE ? Billing::Orders : Billing::Invoices;
		if ($newBilling === Plugin::getInstance()->getBilling()) {
			return;
		}

		$accountTitles = array_map(
			static fn (Account $account): string => (string) $account->title,
			Plugin::getInstance()->getAccounts()->getUnsettledAccounts(),
		);

		if ($accountTitles !== []) {
			$this->addError($attribute, Craft::t(Plugin::HANDLE, 'gateway.paymentTypeUnsettled', [
				'accounts' => implode(', ', $accountTitles),
			]));
		}
	}

	/**
	 * Render a note, a choice of the customer’s accounts with their available credit, and a submit button. Takes the `order`, `submitButtonClasses` and `submitButtonText` params Commerce’s example checkout templates pass.
	 *
	 * @param array<string, mixed> $params
	 */
	public function getPaymentFormHtml(array $params): ?string
	{
		$accounts = Plugin::getInstance()->getAccounts();

		// Fall back to the cart on the storefront, since Commerce's example templates pass no order to other gateways
		$order = $params['order'] ?? (Craft::$app->getRequest()->getIsSiteRequest() ? $this->getCommerce()->getCarts()->getCart() : null);
		$requestedPaymentAmount = $params['paymentAmount'] ?? null;
		$paymentAmount = is_numeric($requestedPaymentAmount) ? (string) $requestedPaymentAmount : (string) ($order instanceof Order ? $order->getOutstandingBalance() : 0);
		$options = $order instanceof Order ? array_map(static fn (Buyer $buyer): array => [
			'account' => $buyer->getAccount(),
			'availableCredit' => $accounts->getAvailableCredit($buyer),
			'canCharge' => $accounts->canCharge($buyer, Amounts::toMoney($paymentAmount, $buyer->getAccount()->getCurrency())),
		], $accounts->getBuyersForOrder($order)) : [];

		return Craft::$app->getView()->renderTemplate('net-terms/_gateway/payment-form', [
			'gateway' => $this,
			'options' => $options,
			// Without an order the form can't check credit, so show the button and leave the decline to the charge
			'canCharge' => ! $order instanceof Order || in_array(true, array_column($options, 'canCharge'), true),
			'submitButtonClasses' => $params['submitButtonClasses'] ?? '',
			'submitButtonText' => $params['submitButtonText'] ?? Craft::t(Plugin::HANDLE, 'gateway.submitButton'),
		], View::TEMPLATE_MODE_CP);
	}

	public function showPaymentFormSubmitButton(): bool
	{
		return false;
	}

	public function getPaymentFormModel(): BasePaymentForm
	{
		return new NetTermsPaymentForm();
	}

	/**
	 * @return array<int, mixed>
	 */
	public function defineRules(): array
	{
		$rules = parent::defineRules();
		$rules[] = [['paymentType'],
			'in',
			'range' => array_keys($this->getPaymentTypeOptions())];
		$rules[] = [['paymentType'], 'validatePaymentType'];

		return $rules;
	}

	public function getSettingsHtml(): ?string
	{
		return null;
	}

	public function availableForUseWithOrder(Order $order): bool
	{
		// Keep Net Terms off the gateways that pay a completed order’s outstanding balance
		if (! parent::availableForUseWithOrder($order) || $order->isCompleted) {
			return false;
		}

		// Show it to every active buyer, so the form can say why a charge is refused
		return Plugin::getInstance()->getAccounts()->getBuyersForOrder($order) !== [];
	}

	/**
	 * @return array{accountId: int|null, amount: float}
	 */
	public function partialPayment(Order $order, float $target, ?int $accountId = null): array
	{
		$selection = [
			'accountId' => null,
			'amount' => 0.0,
		];
		$accounts = Plugin::getInstance()->accounts;
		foreach ($accounts->getBuyersForOrder($order) as $buyer) {
			if ($accountId !== null && $buyer->accountId !== $accountId) {
				continue;
			}

			$account = $accounts->getFreshAccountById((int) $buyer->accountId);
			if (! $account instanceof Account) {
				continue;
			}

			if ($account->status !== AccountStatus::Active) {
				continue;
			}

			if ($account->getCurrency()->getCode() !== $order->currency) {
				continue;
			}

			$available = $accounts->getAvailableCredit($buyer);
			$amount = max(0.0, min($target, $available instanceof Money ? (float) Amounts::toDecimal($available) : $target));
			if ($amount > $selection['amount']) {
				$selection = [
					'accountId' => (int) $buyer->accountId,
					'amount' => $amount,
				];
			}

			if ($amount >= $target) {
				break;
			}
		}

		return $selection;
	}

	/**
	 * Keep the account lock through persistence of a partial payment and its Commerce transaction.
	 *
	 * @template TReturn
	 * @param callable(): TReturn $callback
	 * @return TReturn
	 */
	public function withPartialPaymentLock(Order $order, BasePaymentForm $form, callable $callback): mixed
	{
		$buyer = $this->getChosenBuyer($order, $form);
		if (! $buyer instanceof Buyer) {
			throw new LedgerException(Craft::t(Plugin::HANDLE, 'gateway.notABuyer'));
		}

		return Plugin::getInstance()->ledger->withAccountLock((int) $buyer->accountId, $callback);
	}

	/**
	 * Keep the original account locked while a partial purchase is refunded and recorded in Commerce.
	 *
	 * @template TReturn
	 * @param callable(): TReturn $callback
	 * @return TReturn
	 */
	public function withPartialRefundLock(Transaction $purchase, callable $callback): mixed
	{
		$ledger = Plugin::getInstance()->ledger;
		$charge = $ledger->getChargeByTransactionHash((string) $purchase->hash) ?? $ledger->getAuthorization((string) $purchase->hash);
		if ($charge === null) {
			throw new LedgerException(Craft::t(Plugin::HANDLE, 'gateway.chargeNotFound'));
		}

		return $ledger->withAccountLock((int) $charge->accountId, $callback);
	}

	public function authorizePartial(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
	{
		$order = $transaction->getOrder();
		$buyer = $order instanceof Order ? $this->getChosenBuyer($order, $form) : null;
		if (! $order instanceof Order || ! $buyer instanceof Buyer || $transaction->type !== TransactionRecord::TYPE_AUTHORIZE) {
			return NetTermsResponse::declined(Craft::t(Plugin::HANDLE, 'gateway.notABuyer'));
		}

		try {
			$entry = Plugin::getInstance()->ledger->authorize($buyer, Amounts::toMoney((string) $transaction->amount, $buyer->getAccount()->getCurrency()), (int) $order->id, (string) $transaction->hash);

			return NetTermsResponse::success('net-terms-authorization-' . $entry->id);
		} catch (LedgerException $ledgerException) {
			return NetTermsResponse::declined($ledgerException->getMessage());
		}
	}

	public function getPartialAuthorization(Transaction $authorization): ?Entry
	{
		return Plugin::getInstance()->ledger->getAuthorization((string) $authorization->hash);
	}

	public function resizeAuthorization(Transaction $authorization, float $target): float
	{
		if (! is_finite($target)) {
			throw new LedgerException(Craft::t(Plugin::HANDLE, 'authorization.invalidAmount'));
		}

		$entry = $this->getPartialAuthorization($authorization);
		if (! $entry instanceof Entry) {
			throw new LedgerException(Craft::t(Plugin::HANDLE, 'authorization.notFound'));
		}

		$ledger = Plugin::getInstance()->ledger;

		return $ledger->withAccountLock((int) $entry->accountId, function () use ($authorization, $target, $ledger): float {
			$entry = $this->getPartialAuthorization($authorization);
			if (! $entry instanceof Entry || $entry->status !== \fostercommerce\netterms\enums\EntryStatus::Authorized) {
				throw new LedgerException(Craft::t(Plugin::HANDLE, 'authorization.inactive'));
			}

			$accounts = Plugin::getInstance()->accounts;
			$account = $accounts->getFreshAccountById((int) $entry->accountId);
			$buyer = $accounts->getBuyerById((int) $entry->buyerId);
			if (! $account instanceof Account || $account->status !== AccountStatus::Active || ! $buyer instanceof Buyer || ! $buyer->active) {
				$ledger->releaseAuthorization($entry);

				return 0.0;
			}

			$amount = Amounts::toMoney((string) max(0.0, $target), $entry->amount->getCurrency());
			$available = $accounts->getAvailableCredit($buyer);
			if ($available instanceof Money) {
				$amount = Money::min($amount, $available->add($entry->amount));
			}

			$ledger->resizeAuthorization($entry, $amount);

			return (float) Amounts::toDecimal($amount);
		});
	}

	public function resizePartialAuthorization(Transaction $authorization, Money $amount): RequestResponseInterface
	{
		$entry = $this->getPartialAuthorization($authorization);
		if (! $entry instanceof Entry) {
			return NetTermsResponse::declined(Craft::t(Plugin::HANDLE, 'authorization.notFound'));
		}

		try {
			Plugin::getInstance()->ledger->resizeAuthorization($entry, $amount);

			return NetTermsResponse::success('net-terms-authorization-' . $entry->id);
		} catch (LedgerException $ledgerException) {
			return NetTermsResponse::declined($ledgerException->getMessage());
		}
	}

	public function releaseAuthorization(Transaction $authorization): RequestResponseInterface
	{
		$entry = $this->getPartialAuthorization($authorization);
		if (! $entry instanceof Entry) {
			return NetTermsResponse::declined(Craft::t(Plugin::HANDLE, 'authorization.notFound'));
		}

		try {
			Plugin::getInstance()->ledger->releaseAuthorization($entry);

			return NetTermsResponse::success('net-terms-authorization-' . $entry->id);
		} catch (LedgerException $ledgerException) {
			return NetTermsResponse::declined($ledgerException->getMessage());
		}
	}

	public function purchase(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
	{
		return $this->chargeOrder($transaction, $form);
	}

	public function authorize(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
	{
		return $this->chargeOrder($transaction, $form);
	}

	public function refund(Transaction $transaction): RequestResponseInterface
	{
		/** @var Transaction $parent */
		$parent = $transaction->getParent();

		$ledger = Plugin::getInstance()->getLedger();
		$charge = $ledger->getChargeByTransactionHash((string) $parent->hash);

		// Order-billing captures record money received outside this gateway.
		if ($parent->type === TransactionRecord::TYPE_CAPTURE && $charge === null) {
			return NetTermsResponse::success('net-terms-refund-' . $parent->id);
		}

		if ($charge === null) {
			return NetTermsResponse::declined(Craft::t(Plugin::HANDLE, 'gateway.chargeNotFound'));
		}

		try {
			$refund = $ledger->refund($charge, Amounts::toMoney((string) $transaction->amount, $charge->amount->getCurrency()), (string) $transaction->hash);
		} catch (LedgerException $ledgerException) {
			return NetTermsResponse::declined($ledgerException->getMessage());
		}

		return NetTermsResponse::success('net-terms-entry-' . $refund->id);
	}

	public function capture(Transaction $transaction, string $reference): RequestResponseInterface
	{
		$parent = $transaction->getParent();
		$entry = $parent instanceof Transaction ? $this->getPartialAuthorization($parent) : null;
		if (! $entry instanceof Entry || $entry->orderId !== $transaction->orderId || $transaction->type !== TransactionRecord::TYPE_CAPTURE || $transaction->getOrder()?->isCompleted !== true) {
			return NetTermsResponse::declined(Craft::t(Plugin::HANDLE, 'authorization.notFound'));
		}

		try {
			$captured = Plugin::getInstance()->ledger->captureAuthorization($entry, Amounts::toMoney((string) $transaction->amount, $entry->amount->getCurrency()), (string) $transaction->hash);

			return NetTermsResponse::success('net-terms-entry-' . $captured->id);
		} catch (LedgerException $ledgerException) {
			return NetTermsResponse::declined($ledgerException->getMessage());
		}
	}

	public function completeAuthorize(Transaction $transaction): RequestResponseInterface
	{
		throw new NotImplementedException(Craft::t('commerce', 'This gateway does not support that functionality.'));
	}

	public function completePurchase(Transaction $transaction): RequestResponseInterface
	{
		throw new NotImplementedException(Craft::t('commerce', 'This gateway does not support that functionality.'));
	}

	public function createPaymentSource(BasePaymentForm $sourceData, int $customerId): PaymentSource
	{
		throw new NotImplementedException(Craft::t('commerce', 'This gateway does not support that functionality.'));
	}

	public function deletePaymentSource(string $token): bool
	{
		throw new NotImplementedException(Craft::t('commerce', 'This gateway does not support that functionality.'));
	}

	public function processWebHook(): WebResponse
	{
		throw new NotImplementedException(Craft::t('commerce', 'This gateway does not support that functionality.'));
	}

	public function supportsAuthorize(): bool
	{
		return true;
	}

	public function supportsCapture(): bool
	{
		return false;
	}

	public function supportsCompleteAuthorize(): bool
	{
		return false;
	}

	public function supportsCompletePurchase(): bool
	{
		return false;
	}

	public function supportsPaymentSources(): bool
	{
		return false;
	}

	public function supportsPurchase(): bool
	{
		return true;
	}

	public function supportsRefund(): bool
	{
		return true;
	}

	public function supportsPartialRefund(): bool
	{
		return true;
	}

	public function supportsPartialPayment(): bool
	{
		return $this->paymentType === TransactionRecord::TYPE_PURCHASE;
	}

	public function supportsWebhooks(): bool
	{
		return false;
	}

	/**
	 * The customer’s buyer on the account chosen in the payment form, or on their only account when the form has no account ID.
	 *
	 * @throws InvalidArgumentException if the form isn't a Net Terms payment form
	 */
	private function getChosenBuyer(Order $order, BasePaymentForm $form): ?Buyer
	{
		if (! $form instanceof NetTermsPaymentForm) {
			throw new InvalidArgumentException('The Net Terms gateway needs a NetTermsPaymentForm.');
		}

		$plugin = Plugin::getInstance();
		$buyers = $plugin->getAccounts()->getBuyersForOrder($order);

		// Re-approve a linked order on the account it already charges, whatever the form chose
		$linkedBuyerId = $plugin->getCreditOrders()->getBuyerIdForOrder((int) $order->id);
		if ($linkedBuyerId !== null) {
			return array_column($buyers, null, 'id')[$linkedBuyerId] ?? null;
		}

		if (! is_numeric($form->accountId)) {
			return count($buyers) === 1 ? $buyers[0] : null;
		}

		return array_column($buyers, null, 'accountId')[(int) $form->accountId] ?? null;
	}

	/**
	 * Charge the order to the buyer’s account, as a purchase or an authorization.
	 */
	private function chargeOrder(Transaction $transaction, BasePaymentForm $form): NetTermsResponse
	{
		$order = $transaction->getOrder();
		$plugin = Plugin::getInstance();
		$buyer = $order instanceof Order ? $this->getChosenBuyer($order, $form) : null;

		if (! $order instanceof Order || ! $buyer instanceof Buyer) {
			return NetTermsResponse::declined(Craft::t(Plugin::HANDLE, 'gateway.notABuyer'));
		}

		// Check and charge under one lock so two checkouts can’t both spend the same credit
		return $plugin->getLedger()->withAccountLock((int) $buyer->accountId, function () use ($plugin, $buyer, $order, $transaction): NetTermsResponse {
			$isAuthorization = $transaction->type === TransactionRecord::TYPE_AUTHORIZE;

			// Re-approve a linked order unless the account or buyer is over its limit, since the balance already includes the order
			if ($isAuthorization && $plugin->getCreditOrders()->isCreditOrder($order)) {
				$account = $buyer->getAccount();
				$isOver = $plugin->getAccounts()->getOverLimit($account) instanceof Money
					|| isset($plugin->getAccounts()->getOverSublimitByBuyerId($account)[(int) $buyer->id]);

				return $isOver
					? NetTermsResponse::declined(Craft::t(Plugin::HANDLE, 'gateway.notEnoughCredit'))
					: NetTermsResponse::success('net-terms-' . $order->id);
			}

			// Re-read the buyer and account, since staff can change either while this request waits for the lock
			$account = $plugin->getAccounts()->getFreshAccountById((int) $buyer->accountId);
			$freshBuyer = $plugin->getAccounts()->getBuyerById((int) $buyer->id);

			if (! $account instanceof Account || ! $freshBuyer instanceof Buyer) {
				return NetTermsResponse::declined(Craft::t(Plugin::HANDLE, 'gateway.notABuyer'));
			}

			$amount = Amounts::toMoney((string) $transaction->amount, $account->getCurrency());
			if (! $plugin->getAccounts()->canCharge($freshBuyer, $amount)) {
				return NetTermsResponse::declined(Craft::t(Plugin::HANDLE, 'gateway.notEnoughCredit'));
			}

			// Link the order to its account and buyer in order billing, since the Commerce order records its balance
			if ($isAuthorization) {
				$plugin->getCreditOrders()->linkOrder($order, $freshBuyer);
			} else {
				$plugin->getLedger()->charge($freshBuyer, $amount, (int) $order->id, (string) $transaction->hash);
			}

			return NetTermsResponse::success('net-terms-' . $order->id);
		});
	}

	private function getCommerce(): Commerce
	{
		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();

		return $commerce;
	}
}
