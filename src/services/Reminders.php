<?php

declare(strict_types=1);

namespace fostercommerce\netterms\services;

use Craft;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\elements\Order;
use craft\db\Query;
use craft\elements\User;
use craft\helpers\Db;
use craft\helpers\UrlHelper;
use DateTime;
use fostercommerce\netterms\db\Table;
use fostercommerce\netterms\enums\Billing;
use fostercommerce\netterms\enums\ReminderType;
use fostercommerce\netterms\helpers\Amounts;
use fostercommerce\netterms\models\Account;
use fostercommerce\netterms\models\Invoice;
use fostercommerce\netterms\Plugin;
use fostercommerce\netterms\records\ReminderRecord;
use Money\Money;
use Throwable;
use yii\base\Component;
use yii\db\IntegrityException;

/**
 * Emails reminders about invoices or credit orders that are due soon or overdue, once each.
 */
class Reminders extends Component
{
	/**
	 * System messages for the reminder emails.
	 *
	 * @return array<int, array{key: string, heading: string, subject: string, body: string}>
	 */
	public function getSystemMessages(): array
	{
		return [
			[
				'key' => ReminderType::DueSoon->messageKey(),
				'heading' => Craft::t(Plugin::HANDLE, 'reminder.dueSoonHeading'),
				'subject' => Craft::t(Plugin::HANDLE, 'reminder.dueSoonSubject'),
				'body' => Craft::t(Plugin::HANDLE, 'reminder.dueSoonBody'),
			],
			[
				'key' => ReminderType::Overdue->messageKey(),
				'heading' => Craft::t(Plugin::HANDLE, 'reminder.overdueHeading'),
				'subject' => Craft::t(Plugin::HANDLE, 'reminder.overdueSubject'),
				'body' => Craft::t(Plugin::HANDLE, 'reminder.overdueBody'),
			],
		];
	}

	/**
	 * Send each reminder whose moment, the due date less or plus the setting’s days, is after `$since` and by `$now`.
	 *
	 * @return array{sent: int, failed: int}
	 */
	public function sendReminders(DateTime $since, DateTime $now): array
	{
		$plugin = Plugin::getInstance();
		$settings = $plugin->getSettings();
		$counts = [
			'sent' => 0,
			'failed' => 0,
		];

		foreach ([
			[ReminderType::DueSoon, $settings->dueSoonReminderDays],
			[ReminderType::Overdue, $settings->overdueReminderDays],
		] as [$type, $days]) {
			if ($days === null) {
				continue;
			}

			$results = $plugin->getBilling() === Billing::Orders
				? $this->sendOrderReminders($type, $days, $since, $now)
				: $this->sendInvoiceReminders($type, $days, $since, $now);

			foreach (array_filter($results, is_bool(...)) as $sent) {
				++$counts[$sent ? 'sent' : 'failed'];
			}
		}

		return $counts;
	}

	/**
	 * @return array<int, bool|null> whether each reminder was sent, or null when another run claimed it
	 */
	private function sendInvoiceReminders(ReminderType $type, int $days, DateTime $since, DateTime $now): array
	{
		$invoiceIds = (new Query())
			->select(['invoices.id'])
			->from([
				'invoices' => Table::INVOICES,
			])
			->leftJoin([
				'reminders' => Table::REMINDERS,
			], [
				'and',
				'[[reminders.invoiceId]] = [[invoices.id]]',
				[
					'reminders.type' => $type->value,
				],
			])
			->where([
				'invoices.dateVoided' => null,
				'reminders.id' => null,
			])
			->andWhere(['>', 'invoices.dateDue', Db::prepareDateForDb($this->getDueDateAt($type, $days, $since))])
			->andWhere(['<=', 'invoices.dateDue', Db::prepareDateForDb($this->getDueDateAt($type, $days, $now))])
			->column();

		$invoices = Plugin::getInstance()->getInvoices()->getInvoicesByIds(array_map(intval(...), $invoiceIds));
		$figuresByInvoiceId = Plugin::getInstance()->getInvoices()->getFiguresByInvoiceId(array_values($invoices));

		$results = [];
		foreach ($invoices as $invoiceId => $invoice) {
			$balance = $figuresByInvoiceId[$invoiceId]['balance'];
			if (! $balance->isPositive()) {
				continue;
			}

			if (! $invoice->dateDue instanceof DateTime) {
				continue;
			}

			if ($this->isBeforeBill($type, $days, $invoice->getPaymentTerms())) {
				continue;
			}

			$results[] = $this->sendInvoiceReminder($type, $invoice, $invoice->dateDue, $balance);
		}

		return $results;
	}

	/**
	 * @return array<int, bool|null> whether each reminder was sent, or null when another run claimed it
	 */
	private function sendOrderReminders(ReminderType $type, int $days, DateTime $since, DateTime $now): array
	{
		$plugin = Plugin::getInstance();
		$accountIdsByOrderId = (new Query())
			->select(['creditOrders.orderId', 'creditOrders.accountId'])
			->from([
				'creditOrders' => Table::ORDERS,
			])
			->leftJoin([
				'reminders' => Table::REMINDERS,
			], [
				'and',
				'[[reminders.orderId]] = [[creditOrders.orderId]]',
				[
					'reminders.type' => $type->value,
				],
			])
			->innerJoin([
				'orders' => CommerceTable::ORDERS,
			], '[[orders.id]] = [[creditOrders.orderId]]')
			->where([
				'orders.isCompleted' => true,
				'reminders.id' => null,
			])
			->andWhere('[[orders.totalPaid]] < [[orders.totalPrice]]')
			->pairs();

		/** @var Order[] $orders */
		$orders = Order::find()
			->id(array_keys($accountIdsByOrderId))
			->all();

		$dueAfter = $this->getDueDateAt($type, $days, $since);
		$dueBy = $this->getDueDateAt($type, $days, $now);
		$results = [];
		foreach ($orders as $order) {
			$account = $plugin->getAccounts()->getAccountById((int) $accountIdsByOrderId[$order->id]);
			if (! $account instanceof Account) {
				continue;
			}

			// Work out the due date here, since terms vary by account and order
			$dateDue = $plugin->getCreditOrders()->getDateDue($order, $account);
			if (! $dateDue instanceof DateTime) {
				continue;
			}

			if ($dateDue <= $dueAfter) {
				continue;
			}

			if ($dateDue > $dueBy) {
				continue;
			}

			if ($this->isBeforeBill($type, $days, $plugin->getCreditOrders()->getPaymentTerms($order, $account))) {
				continue;
			}

			$results[] = $this->sendOrderReminder($type, $order, $dateDue);
		}

		return $results;
	}

	/**
	 * The due date whose reminder moment is `$moment`.
	 */
	private function getDueDateAt(ReminderType $type, int $days, DateTime $moment): DateTime
	{
		return (clone $moment)->modify(match ($type) {
			ReminderType::DueSoon => "+{$days} days",
			ReminderType::Overdue => "-{$days} days",
		});
	}

	/**
	 * Whether a due-soon moment came before the invoice or order existed, as with 30-day reminders on 15-day terms.
	 */
	private function isBeforeBill(ReminderType $type, int $days, int $paymentTerms): bool
	{
		return $type === ReminderType::DueSoon && $days > $paymentTerms;
	}

	private function sendInvoiceReminder(ReminderType $type, Invoice $invoice, DateTime $dateDue, Money $balance): ?bool
	{
		$account = $invoice->getAccount();
		$holder = $account->getHolder();

		if (! $holder instanceof User || $holder->email === null) {
			Craft::warning(sprintf('No reminder was sent for invoice %s, because account %d has no holder email address.', $invoice->number, $account->id), Plugin::HANDLE);

			return false;
		}

		return $this->send($type, [
			'invoiceId' => $invoice->id,
		], $holder->email, [
			'recipientName' => $holder->getName(),
			'label' => Craft::t(Plugin::HANDLE, 'reminder.invoiceLabel', [
				'number' => $invoice->number,
			]),
			'amountDue' => Amounts::toString($balance),
			'dateDue' => Craft::$app->getFormatter()->asDate($dateDue, 'long'),
			'link' => Plugin::getInstance()->getInvoices()->getStorefrontUrl($invoice),
		]);
	}

	private function sendOrderReminder(ReminderType $type, Order $order, DateTime $dateDue): ?bool
	{
		$creditOrders = Plugin::getInstance()->getCreditOrders();
		$orderPath = Plugin::getInstance()->getSettings()->orderPath;

		return $this->send($type, [
			'orderId' => $order->id,
		], (string) $order->email, [
			'recipientName' => $order->getCustomer()?->getName() ?? $order->email,
			'label' => Craft::t(Plugin::HANDLE, 'reminder.orderLabel', [
				'reference' => $creditOrders->getLabel($order),
			]),
			'amountDue' => Amounts::toString($creditOrders->getBalance($order)),
			'dateDue' => Craft::$app->getFormatter()->asDate($dateDue, 'long'),
			'link' => $orderPath !== null ? UrlHelper::siteUrl(str_replace('{number}', (string) $order->number, $orderPath), null, null, $order->orderSiteId) : null,
		]);
	}

	/**
	 * Send the reminder once, or return null when another run already claimed it.
	 *
	 * @param array{invoiceId?: int|null, orderId?: int|null} $target
	 * @param array<string, string|null> $variables
	 */
	private function send(ReminderType $type, array $target, string $email, array $variables): ?bool
	{
		$reminderRecord = new ReminderRecord();
		$reminderRecord->type = $type->value;
		$reminderRecord->invoiceId = $target['invoiceId'] ?? null;
		$reminderRecord->orderId = $target['orderId'] ?? null;

		// Write the row before sending, so the unique index stops a concurrent run sending the reminder twice
		try {
			$reminderRecord->save(false);
		} catch (IntegrityException) {
			// Another run claimed the reminder, so leave it out of this run's counts
			return null;
		}

		// Catch render errors too, since an admin's edit to the system message can break its Twig
		try {
			$sent = Craft::$app->getMailer()
				->composeFromKey($type->messageKey(), $variables)
				->setTo($email)
				->send();
		} catch (Throwable $throwable) {
			Craft::error(sprintf('The %s reminder to %s was not sent: %s', $type->value, $email, $throwable->getMessage()), Plugin::HANDLE);
			$sent = false;
		}

		if (! $sent) {
			$reminderRecord->delete();
		}

		return $sent;
	}
}
