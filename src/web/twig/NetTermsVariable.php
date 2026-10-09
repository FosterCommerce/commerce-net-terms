<?php

declare(strict_types=1);

namespace fostercommerce\netterms\web\twig;

use Craft;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\elements\User;
use DateTime;
use fostercommerce\netterms\enums\Billing;
use fostercommerce\netterms\enums\InvoiceStatus;
use fostercommerce\netterms\helpers\Amounts;
use fostercommerce\netterms\models\Account;
use fostercommerce\netterms\models\Buyer;
use fostercommerce\netterms\models\Invoice;
use fostercommerce\netterms\models\InvoiceLine;
use fostercommerce\netterms\Plugin;
use Money\Money;

/**
 * Read-only access to accounts for storefront templates, as `craft.netTerms`.
 */
class NetTermsVariable
{
	/**
	 * The account the current user holds or buys on, in the current store.
	 */
	public function getAccount(): ?Account
	{
		$currentUser = $this->getCurrentUser();
		if (! $currentUser instanceof User) {
			return null;
		}

		$accounts = Plugin::getInstance()->getAccounts();
		$storeId = $this->getCurrentStoreId();
		$heldAccount = $accounts->getAccountByHolder($storeId, (int) $currentUser->id);

		if ($heldAccount instanceof Account) {
			return $heldAccount;
		}

		foreach ($accounts->getAccountsByBuyerUserId((int) $currentUser->id) as $account) {
			if ($account->storeId === $storeId) {
				return $account;
			}
		}

		return null;
	}

	/**
	 * The account a holder’s user ID has, when the current user can see it.
	 */
	public function getAccountByHolderId(int $holderId): ?Account
	{
		$account = Plugin::getInstance()->getAccounts()->getAccountByHolder($this->getCurrentStoreId(), $holderId);

		return $account instanceof Account && $this->canView($account) ? $account : null;
	}

	/**
	 * The current user’s buyer record on an account.
	 */
	public function getCurrentBuyer(Account $account): ?Buyer
	{
		$currentUser = $this->getCurrentUser();

		return $currentUser instanceof User ? Plugin::getInstance()->getAccounts()->getBuyer($account, (int) $currentUser->id) : null;
	}

	/**
	 * @return Buyer[]
	 */
	public function getBuyers(Account $account): array
	{
		return $this->canView($account) ? $account->getBuyers() : [];
	}

	public function getAvailableCredit(Buyer $buyer): ?Money
	{
		return $this->canView($buyer->getAccount()) ? Plugin::getInstance()->getAccounts()->getAvailableCredit($buyer) : null;
	}

	public function getOwed(Account $account): ?Money
	{
		return $this->canView($account) ? Plugin::getInstance()->getAccounts()->getOwedByAccount($account) : null;
	}

	public function getOwedByBuyer(Buyer $buyer): ?Money
	{
		$account = $buyer->getAccount();

		return $this->canView($account) ? Plugin::getInstance()->getAccounts()->getOwedByBuyer($buyer) : null;
	}

	public function getUnappliedCredit(Account $account): ?Money
	{
		return $this->canView($account) ? Plugin::getInstance()->getPayments()->getUnappliedByAccount($account) : null;
	}

	public function canManage(Account $account): bool
	{
		$currentUser = $this->getCurrentUser();

		return $currentUser instanceof User && Plugin::getInstance()->getAccounts()->canManage($account, $currentUser);
	}

	/**
	 * @return Invoice[]
	 */
	public function getInvoices(Account $account): array
	{
		return $this->canView($account) ? Plugin::getInstance()->getInvoices()->getInvoicesByAccountId((int) $account->id) : [];
	}

	public function getInvoiceByNumber(string $number): ?Invoice
	{
		$invoice = Plugin::getInstance()->getInvoices()->getInvoiceByNumber($number);

		return $invoice instanceof Invoice && $this->canView($invoice->getAccount()) ? $invoice : null;
	}

	/**
	 * @return InvoiceLine[]
	 */
	public function getOpenLines(Account $account): array
	{
		return $this->canView($account) ? Plugin::getInstance()->getInvoices()->getOpenLinesByAccountId((int) $account->id) : [];
	}

	public function getInvoiceTotal(Invoice $invoice): ?Money
	{
		return $this->canView($invoice->getAccount()) ? Plugin::getInstance()->getInvoices()->getTotal($invoice) : null;
	}

	public function getInvoiceBalance(Invoice $invoice): ?Money
	{
		return $this->canView($invoice->getAccount()) ? Plugin::getInstance()->getInvoices()->getBalance($invoice) : null;
	}

	public function getInvoiceStatus(Invoice $invoice): ?InvoiceStatus
	{
		return $this->canView($invoice->getAccount()) ? Plugin::getInstance()->getInvoices()->getStatus($invoice) : null;
	}

	public function getLineAmount(InvoiceLine $line): ?Money
	{
		return $this->canView($line->getInvoice()->getAccount()) ? Plugin::getInstance()->getInvoices()->getLineAmount($line) : null;
	}

	public function getLineBalance(InvoiceLine $line): ?Money
	{
		return $this->canView($line->getInvoice()->getAccount()) ? Plugin::getInstance()->getInvoices()->getLineBalance($line) : null;
	}

	/**
	 * What Net Terms bills: invoices the plugin issues, or the orders themselves.
	 */
	public function getBilling(): Billing
	{
		return Plugin::getInstance()->getBilling();
	}

	/**
	 * The account’s orders charged to it, newest first. Pass `true` for only those with a balance, oldest first.
	 *
	 * @return Order[]
	 */
	public function getOrders(Account $account, bool $openOnly = false): array
	{
		if (! $this->canView($account)) {
			return [];
		}

		$creditOrders = Plugin::getInstance()->getCreditOrders();

		return $openOnly ? $creditOrders->getOpenOrders($account) : $creditOrders->createOrderQuery($account)->all();
	}

	public function getOrderBalance(Order $order): ?Money
	{
		return $this->canViewOrder($order) ? Plugin::getInstance()->getCreditOrders()->getBalance($order) : null;
	}

	public function getOrderDateDue(Order $order): ?DateTime
	{
		$account = Plugin::getInstance()->getCreditOrders()->getAccountForOrder($order);

		return $account instanceof Account && $this->canView($account) ? Plugin::getInstance()->getCreditOrders()->getDateDue($order, $account) : null;
	}

	public function getIsOrderOverdue(Order $order): bool
	{
		$account = Plugin::getInstance()->getCreditOrders()->getAccountForOrder($order);

		return $account instanceof Account && $this->canView($account) && Plugin::getInstance()->getCreditOrders()->getIsOverdue($order, $account);
	}

	/**
	 * Format an amount as a localized number, for a form input.
	 */
	public function toNumber(Money $money): ?string
	{
		return Amounts::toNumber($money);
	}

	/**
	 * The holder, an active buyer, or anyone who can manage the account can see it.
	 */
	private function canView(Account $account): bool
	{
		$currentUser = $this->getCurrentUser();
		if (! $currentUser instanceof User) {
			return false;
		}

		$accounts = Plugin::getInstance()->getAccounts();

		return $account->holderId === $currentUser->id
			|| $accounts->getBuyer($account, (int) $currentUser->id)?->active === true
			|| $accounts->canManage($account, $currentUser);
	}

	private function canViewOrder(Order $order): bool
	{
		$account = Plugin::getInstance()->getCreditOrders()->getAccountForOrder($order);

		return $account instanceof Account && $this->canView($account);
	}

	private function getCurrentUser(): ?User
	{
		return Craft::$app->getUser()->getIdentity();
	}

	private function getCurrentStoreId(): int
	{
		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();

		return (int) $commerce->getStores()->getCurrentStore()->id;
	}
}
