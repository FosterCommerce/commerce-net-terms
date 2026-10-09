<?php

declare(strict_types=1);

namespace fostercommerce\netterms;

use Craft;
use craft\base\Element;
use craft\base\Model;
use craft\base\Plugin as BasePlugin;
use craft\commerce\elements\Order;
use craft\commerce\Plugin as Commerce;
use craft\commerce\records\Transaction as TransactionRecord;
use craft\commerce\services\Gateways;
use craft\console\Application as ConsoleApplication;
use craft\elements\User;
use craft\events\DefineElementDeletionBlockersEvent;
use craft\events\ModelEvent;
use craft\events\RegisterComponentTypesEvent;
use craft\events\RegisterEmailMessagesEvent;
use craft\events\RegisterUrlRulesEvent;
use craft\events\RegisterUserPermissionsEvent;
use craft\helpers\Queue;
use craft\helpers\StringHelper;
use craft\services\Fields;
use craft\services\SystemMessages;
use craft\services\UserPermissions;
use craft\web\twig\variables\CraftVariable;
use craft\web\UrlManager;
use fostercommerce\netterms\elements\InvoicedOrdersBlocker;
use fostercommerce\netterms\elements\LedgerUsersBlocker;
use fostercommerce\netterms\enums\Billing;
use fostercommerce\netterms\enums\SublimitMode;
use fostercommerce\netterms\fields\PaymentTerms;
use fostercommerce\netterms\gateways\NetTerms;
use fostercommerce\netterms\helpers\Amounts;
use fostercommerce\netterms\jobs\FollowTotalChangeJob;
use fostercommerce\netterms\models\Entry;
use fostercommerce\netterms\models\Settings;
use fostercommerce\netterms\services\Accounts;
use fostercommerce\netterms\services\CreditOrders;
use fostercommerce\netterms\services\Invoices;
use fostercommerce\netterms\services\Ledger;
use fostercommerce\netterms\services\Payments;
use fostercommerce\netterms\services\Reminders;
use fostercommerce\netterms\web\twig\NetTermsVariable;
use yii\base\Event;

/**
 * @method static Plugin getInstance()
 * @property-read Settings $settings
 * @property-read Accounts $accounts
 * @property-read Ledger $ledger
 * @property-read Invoices $invoices
 * @property-read Payments $payments
 * @property-read CreditOrders $creditOrders
 * @property-read Reminders $reminders
 */
class Plugin extends BasePlugin
{
	public const HANDLE = 'net-terms';

	public const PERMISSION_MANAGE_ACCOUNTS = 'net-terms-manageAccounts';

	public const PERMISSION_MANAGE_PAYMENTS = 'net-terms-managePayments';

	public bool $hasCpSection = true;

	public bool $hasCpSettings = true;

	public string $schemaVersion = '1.0.0';

	/**
	 * @var array<int, string>
	 */
	private array $totalsBeforeSave = [];

	/**
	 * @return array<string, mixed>
	 */
	public static function config(): array
	{
		return [
			'components' => [
				'accounts' => Accounts::class,
				'ledger' => Ledger::class,
				'invoices' => Invoices::class,
				'payments' => Payments::class,
				'creditOrders' => CreditOrders::class,
				'reminders' => Reminders::class,
			],
		];
	}

	public function init(): void
	{
		parent::init();

		if (Craft::$app instanceof ConsoleApplication) {
			$this->controllerNamespace = 'fostercommerce\\netterms\\console\\controllers';
		}

		Event::on(
			Fields::class,
			Fields::EVENT_REGISTER_FIELD_TYPES,
			static function (RegisterComponentTypesEvent $event): void {
				$event->types[] = PaymentTerms::class;
			},
		);

		Event::on(
			Gateways::class,
			Gateways::EVENT_REGISTER_GATEWAY_TYPES,
			static function (RegisterComponentTypesEvent $event): void {
				$event->types[] = NetTerms::class;
			},
		);

		Event::on(
			SystemMessages::class,
			SystemMessages::EVENT_REGISTER_MESSAGES,
			function (RegisterEmailMessagesEvent $event): void {
				$event->messages = [...$event->messages, ...$this->getReminders()->getSystemMessages()];
			},
		);

		$this->registerUserDeleteGuard();
		$this->registerOrderDeleteGuard();
		$this->registerOrderEditHandler();

		Event::on(
			CraftVariable::class,
			CraftVariable::EVENT_INIT,
			static function (Event $event): void {
				/** @var CraftVariable $variable */
				$variable = $event->sender;
				$variable->set('netTerms', NetTermsVariable::class);
			},
		);

		$this->registerCpUrlRules();
		$this->registerPermissions();
	}

	/**
	 * The Net Terms gateway’s payment type is the billing mode: purchase bills on invoices, authorize leaves orders unpaid to bill by order.
	 */
	public function getBilling(): Billing
	{
		return $this->getNetTermsGateway()?->paymentType === TransactionRecord::TYPE_AUTHORIZE ? Billing::Orders : Billing::Invoices;
	}

	public function getNetTermsGateway(): ?NetTerms
	{
		foreach ($this->getCommerce()->getGateways()->getAllGateways() as $gateway) {
			if ($gateway instanceof NetTerms) {
				return $gateway;
			}
		}

		return null;
	}

	public function getSettings(): Settings
	{
		/** @var Settings $settings */
		$settings = parent::getSettings();

		return $settings;
	}

	public function getAccounts(): Accounts
	{
		/** @var Accounts $accounts */
		$accounts = $this->get('accounts');

		return $accounts;
	}

	public function getLedger(): Ledger
	{
		/** @var Ledger $ledger */
		$ledger = $this->get('ledger');

		return $ledger;
	}

	public function getInvoices(): Invoices
	{
		/** @var Invoices $invoices */
		$invoices = $this->get('invoices');

		return $invoices;
	}

	public function getPayments(): Payments
	{
		/** @var Payments $payments */
		$payments = $this->get('payments');

		return $payments;
	}

	public function getCreditOrders(): CreditOrders
	{
		/** @var CreditOrders $creditOrders */
		$creditOrders = $this->get('creditOrders');

		return $creditOrders;
	}

	public function getReminders(): Reminders
	{
		/** @var Reminders $reminders */
		$reminders = $this->get('reminders');

		return $reminders;
	}

	/**
	 * @return array<string, mixed>|null
	 */
	public function getCpNavItem(): ?array
	{
		/** @var array<string, mixed> $navItem */
		$navItem = parent::getCpNavItem();
		$navItem['label'] = Craft::t(self::HANDLE, 'nav.netTerms');
		$navItem['subnav'] = [];
		$navItem['subnav']['accounts'] = [
			'label' => Craft::t(self::HANDLE, 'nav.accounts'),
			'url' => 'net-terms/accounts',
		];

		if ($this->getBilling() === Billing::Invoices) {
			$navItem['subnav']['invoices'] = [
				'label' => Craft::t(self::HANDLE, 'nav.invoices'),
				'url' => 'net-terms/invoices',
			];
		}

		$navItem['subnav']['payments'] = [
			'label' => Craft::t(self::HANDLE, 'nav.payments'),
			'url' => 'net-terms/payments',
		];

		return $navItem;
	}

	protected function createSettingsModel(): ?Model
	{
		return new Settings();
	}

	protected function settingsHtml(): ?string
	{
		return Craft::$app->getView()->renderTemplate('net-terms/_settings', [
			'settings' => $this->getSettings(),
			'sublimitModeOptions' => SublimitMode::labelMap(),
			'netTermsGateway' => $this->getNetTermsGateway(),
			'isOrderBilling' => $this->getBilling() === Billing::Orders,
		]);
	}

	/**
	 * In invoice billing, refuse an order edit that raises the total past available credit, and settle a change to the total on the order and the ledger.
	 */
	private function registerOrderEditHandler(): void
	{
		Event::on(
			Order::class,
			Model::EVENT_AFTER_VALIDATE,
			function (Event $event): void {
				if ($this->getBilling() === Billing::Invoices) {
					/** @var Order $order */
					$order = $event->sender;
					$this->getCreditOrders()->validateTotalChange($order);
				}
			},
		);

		Event::on(
			Order::class,
			Element::EVENT_BEFORE_SAVE,
			function (ModelEvent $event): void {
				/** @var Order $order */
				$order = $event->sender;

				// Read the total before the save, since the order recalculates while it saves
				if ($this->getBilling() === Billing::Invoices && $order->isCompleted && $order->id !== null) {
					// Keep the first total when a listener saves the order again inside this save
					$this->totalsBeforeSave[$order->id] ??= $this->getCreditOrders()->getStoredTotal($order->id);
				}
			},
		);

		Event::on(
			Order::class,
			Element::EVENT_AFTER_SAVE,
			function (ModelEvent $event): void {
				/** @var Order $order */
				$order = $event->sender;
				$totalBeforeSave = $this->totalsBeforeSave[$order->id] ?? null;
				unset($this->totalsBeforeSave[$order->id]);
				if ($totalBeforeSave === null) {
					return;
				}

				$charge = $this->getLedger()->getChargeByOrderId((int) $order->id);
				if (! $charge instanceof Entry) {
					return;
				}

				$currency = $charge->amount->getCurrency();
				$change = Amounts::toMoney((string) $order->getTotalPrice(), $currency)->subtract(Amounts::toMoney((string) $totalBeforeSave, $currency));
				if (! $change->isZero()) {
					Queue::push(new FollowTotalChangeJob([
						'orderId' => $order->id,
						'orderReference' => (string) $order->reference,
						'change' => Amounts::toDecimal($change),
						'editKey' => 'net-terms-edit:' . StringHelper::UUID(),
						'authorId' => Craft::$app->getUser()->getIdentity()?->id,
					]));
				}
			},
		);
	}

	private function registerOrderDeleteGuard(): void
	{
		Event::on(
			Order::class,
			Element::EVENT_DEFINE_DELETION_BLOCKERS,
			static function (DefineElementDeletionBlockersEvent $event): void {
				$event->blockers[] = new InvoicedOrdersBlocker($event->elements, $event->hardDelete);
			},
		);

		// Refuse from code and the order page too, which skip the blockers
		// Refuse trashing too, since a trashed order is later deleted for good with its invoiced entries
		Event::on(
			Order::class,
			Element::EVENT_BEFORE_DELETE,
			function (ModelEvent $event): void {
				/** @var Order $order */
				$order = $event->sender;

				if ($this->getLedger()->getInvoicedOrderIds([(int) $order->id]) !== []) {
					$event->isValid = false;
				}
			},
		);
	}

	private function registerUserDeleteGuard(): void
	{
		Event::on(
			User::class,
			Element::EVENT_DEFINE_DELETION_BLOCKERS,
			static function (DefineElementDeletionBlockersEvent $event): void {
				$event->blockers[] = new LedgerUsersBlocker($event->elements, $event->hardDelete);
			},
		);

		// Refuse to delete a user with ledger history, since the foreign keys would delete or orphan it
		Event::on(
			User::class,
			Element::EVENT_BEFORE_DELETE,
			function (ModelEvent $event): void {
				/** @var User $user */
				$user = $event->sender;

				if ($this->getAccounts()->hasLedgerHistory((int) $user->id)) {
					$event->isValid = false;
				}
			},
		);
	}

	private function registerCpUrlRules(): void
	{
		Event::on(
			UrlManager::class,
			UrlManager::EVENT_REGISTER_CP_URL_RULES,
			static function (RegisterUrlRulesEvent $event): void {
				$event->rules['net-terms'] = 'net-terms/accounts/index';
				$event->rules['net-terms/accounts'] = 'net-terms/accounts/index';
				$event->rules['net-terms/accounts/new'] = 'net-terms/accounts/edit';
				$event->rules['net-terms/accounts/<accountId:\d+>'] = 'net-terms/accounts/edit';
				$event->rules['net-terms/accounts/<accountId:\d+>/<tab:buyers|invoices|orders|payments|ledger>'] = 'net-terms/accounts/edit';
				$event->rules['net-terms/invoices'] = 'net-terms/invoices/index';
				$event->rules['net-terms/invoices/<invoiceId:\d+>'] = 'net-terms/invoices/view';
				$event->rules['net-terms/payments'] = 'net-terms/payments/index';
				$event->rules['net-terms/payments/new'] = 'net-terms/payments/edit';
				$event->rules['net-terms/payments/<paymentId:\d+>'] = 'net-terms/payments/view';
			},
		);
	}

	private function registerPermissions(): void
	{
		Event::on(
			UserPermissions::class,
			UserPermissions::EVENT_REGISTER_PERMISSIONS,
			static function (RegisterUserPermissionsEvent $event): void {
				$event->permissions[] = [
					'heading' => Craft::t(self::HANDLE, 'nav.netTerms'),
					'permissions' => [
						self::PERMISSION_MANAGE_ACCOUNTS => [
							'label' => Craft::t(self::HANDLE, 'permissions.manageAccounts'),
						],
						self::PERMISSION_MANAGE_PAYMENTS => [
							'label' => Craft::t(self::HANDLE, 'permissions.managePayments'),
						],
					],
				];
			},
		);
	}

	private function getCommerce(): Commerce
	{
		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();

		return $commerce;
	}
}
