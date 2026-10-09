<?php

declare(strict_types=1);

namespace fostercommerce\netterms\gateways;

use Craft;
use craft\commerce\base\Gateway;
use craft\commerce\base\RequestResponseInterface;
use craft\commerce\elements\Order;
use craft\commerce\errors\NotImplementedException;
use craft\commerce\models\payments\BasePaymentForm;
use craft\commerce\models\payments\OffsitePaymentForm;
use craft\commerce\models\PaymentSource;
use craft\commerce\models\Transaction;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\web\Response as WebResponse;
use craft\web\View;
use fostercommerce\netterms\enums\Billing;
use fostercommerce\netterms\errors\LedgerException;
use fostercommerce\netterms\helpers\Amounts;
use fostercommerce\netterms\models\Account;
use fostercommerce\netterms\models\Buyer;
use fostercommerce\netterms\models\responses\NetTermsResponse;
use fostercommerce\netterms\Plugin;
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
	 * Refuse a change of payment type while any account is unsettled, because each billing mode counts what an account owes from different records.
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

		$holderNames = array_map(
			static fn (Account $account): string => (string) $account->getHolder()?->getName(),
			Plugin::getInstance()->getAccounts()->getUnsettledAccounts(),
		);

		if ($holderNames !== []) {
			$this->addError($attribute, Craft::t(Plugin::HANDLE, 'gateway.paymentTypeUnsettled', [
				'accounts' => implode(', ', $holderNames),
			]));
		}
	}

	/**
	 * Render a note, the buyer’s available credit and a submit button. Takes the `order`, `submitButtonClasses` and `submitButtonText` params Commerce’s example checkout templates pass.
	 *
	 * @param array<string, mixed> $params
	 */
	public function getPaymentFormHtml(array $params): ?string
	{
		// Fall back to the cart on the storefront, since Commerce's example templates pass no order to other gateways
		$order = $params['order'] ?? (Craft::$app->getRequest()->getIsSiteRequest() ? $this->getCommerce()->getCarts()->getCart() : null);
		$buyer = $order instanceof Order ? $this->getBuyerForOrder($order) : null;
		$availableCredit = $buyer instanceof Buyer ? Plugin::getInstance()->getAccounts()->getAvailableCredit($buyer) : null;
		$outstanding = $buyer instanceof Buyer && $order instanceof Order ? Amounts::toMoney((string) $order->getOutstandingBalance(), $buyer->getAccount()->getCurrency()) : null;

		return Craft::$app->getView()->renderTemplate('net-terms/_gateway/payment-form', [
			'gateway' => $this,
			'availableCredit' => $availableCredit,
			// Without an order the form can't check credit, so show the button and leave the decline to the charge
			'canCharge' => ! $order instanceof Order || ($buyer instanceof Buyer && (! $availableCredit instanceof Money || ($outstanding instanceof Money && $availableCredit->greaterThanOrEqual($outstanding)))),
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
		return new OffsitePaymentForm();
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
		// Never offer Net Terms to pay what a completed order still owes
		if (! parent::availableForUseWithOrder($order) || $order->isCompleted) {
			return false;
		}

		// Offer it to every active buyer, so the form can say why a charge is refused
		return $this->getBuyerForOrder($order)?->active === true;
	}

	public function purchase(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
	{
		return $this->chargeOrder($transaction);
	}

	public function authorize(Transaction $transaction, BasePaymentForm $form): RequestResponseInterface
	{
		return $this->chargeOrder($transaction);
	}

	public function refund(Transaction $transaction): RequestResponseInterface
	{
		/** @var Transaction $parent */
		$parent = $transaction->getParent();

		// Approve a refund of a recorded payment, since that money was returned outside this gateway
		if ($parent->type === TransactionRecord::TYPE_CAPTURE) {
			return NetTermsResponse::success('net-terms-refund-' . $parent->id);
		}

		$ledger = Plugin::getInstance()->getLedger();
		$charge = $parent->hash !== null ? $ledger->getChargeByTransactionHash($parent->hash) : null;

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
		throw new NotImplementedException(Craft::t('commerce', 'This gateway does not support that functionality.'));
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
		return false;
	}

	public function supportsWebhooks(): bool
	{
		return false;
	}

	private function getBuyerForOrder(Order $order): ?Buyer
	{
		$accounts = Plugin::getInstance()->getAccounts();
		$account = $accounts->getAccountForOrder($order);

		return $account instanceof Account ? $accounts->getBuyerForOrder($order, $account) : null;
	}

	/**
	 * Charge the order to the buyer’s account, as a purchase or an authorization.
	 */
	private function chargeOrder(Transaction $transaction): NetTermsResponse
	{
		$order = $transaction->getOrder();
		$plugin = Plugin::getInstance();
		$buyer = $order instanceof Order ? $this->getBuyerForOrder($order) : null;

		if (! $order instanceof Order || ! $buyer instanceof Buyer) {
			return NetTermsResponse::declined(Craft::t(Plugin::HANDLE, 'gateway.notABuyer'));
		}

		// Check and charge under one lock so two checkouts can’t both spend the same credit
		return $plugin->getLedger()->withAccountLock((int) $buyer->accountId, function () use ($plugin, $buyer, $order, $transaction): NetTermsResponse {
			$isAuthorization = $transaction->type === TransactionRecord::TYPE_AUTHORIZE;

			// Approve a second authorization of an order already linked only within its limits, since what it owes already counts its current total
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

			// Link the order to its account and buyer in order billing, since the Commerce order records what it owes
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
