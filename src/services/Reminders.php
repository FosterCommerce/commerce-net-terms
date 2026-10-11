<?php

declare(strict_types=1);

namespace fostercommerce\netterms\services;

use Craft;
use craft\commerce\db\Table as CommerceTable;
use craft\commerce\elements\Order;
use craft\commerce\models\Email as CommerceEmail;
use craft\commerce\Plugin as Commerce;
use craft\db\Query;
use craft\helpers\Db;
use DateTime;
use fostercommerce\netterms\db\Table;
use fostercommerce\netterms\enums\Billing;
use fostercommerce\netterms\enums\ReminderType;
use fostercommerce\netterms\helpers\Amounts;
use fostercommerce\netterms\models\Account;
use fostercommerce\netterms\models\Email;
use fostercommerce\netterms\models\Invoice;
use fostercommerce\netterms\Plugin;
use fostercommerce\netterms\records\ReminderRecord;
use Throwable;
use yii\base\Component;
use yii\db\IntegrityException;

/**
 * Emails reminders about invoices or orders that are due soon or overdue, once each.
 */
class Reminders extends Component
{
	/**
	 * Send each enabled reminder whose moment, the due date less or plus the reminder’s days, is after `$windowStart` and by `$windowEnd`.
	 *
	 * @return array{sent: int, failed: int}
	 */
	public function sendReminders(DateTime $windowStart, DateTime $windowEnd): array
	{
		$plugin = Plugin::getInstance();
		$results = [];

		if ($plugin->getBilling() === Billing::Orders) {
			foreach ($plugin->getSettings()->reminders as $reminder) {
				$emailUid = $reminder['emailUid'] ?? null;

				if (! $reminder['enabled']) {
					continue;
				}

				// Skip a row with no Commerce email chosen yet, rather than fail each order it covers
				if ($emailUid === null) {
					continue;
				}

				$results = [...$results, ...$this->sendOrderReminders(ReminderType::from($reminder['type']), (int) $reminder['days'], $emailUid, $windowStart, $windowEnd)];
			}
		} else {
			foreach ($plugin->getEmails()->getReminderEmails() as $email) {
				if ($email->enabled) {
					$results = [...$results, ...$this->sendInvoiceReminders($email, $windowStart, $windowEnd)];
				}
			}
		}

		$sentResults = array_filter($results, is_bool(...));
		$sentCount = count(array_filter($sentResults));

		return [
			'sent' => $sentCount,
			'failed' => count($sentResults) - $sentCount,
		];
	}

	/**
	 * @return array<int, bool|null> whether each reminder was sent, or null when another run claimed it
	 */
	private function sendInvoiceReminders(Email $email, DateTime $windowStart, DateTime $windowEnd): array
	{
		$type = ReminderType::from($email->trigger->value);
		$days = (int) $email->days;
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
					'reminders.emailId' => $email->id,
					'reminders.type' => $type->value,
					'reminders.days' => $days,
				],
			])
			->where([
				'invoices.dateVoided' => null,
				'reminders.id' => null,
			])
			->andWhere(['not', [
				'invoices.dateIssued' => null,
			]])
			->andWhere(['>', 'invoices.dateDue', Db::prepareDateForDb($this->getDueDateAt($type, $days, $windowStart))])
			->andWhere(['<=', 'invoices.dateDue', Db::prepareDateForDb($this->getDueDateAt($type, $days, $windowEnd))])
			->column();

		$invoices = Plugin::getInstance()->getInvoices()->getInvoicesByIds(array_map(intval(...), $invoiceIds));
		$figuresByInvoiceId = Plugin::getInstance()->getInvoices()->getFiguresByInvoiceId(array_values($invoices));

		$results = [];
		foreach ($invoices as $invoiceId => $invoice) {
			$balance = $figuresByInvoiceId[$invoiceId]['balance'];
			if (! $balance->isPositive()) {
				continue;
			}

			if ($this->isBeforeBill($type, $days, $invoice->getPaymentTerms())) {
				continue;
			}

			$results[] = $this->send($type, $days, [
				'emailId' => $email->id,
				'invoiceId' => $invoice->id,
			], static fn (): bool => Plugin::getInstance()->getEmails()->send($email, $invoice));
		}

		return $results;
	}

	/**
	 * @return array<int, bool|null> whether each reminder was sent, or null when another run claimed it
	 */
	private function sendOrderReminders(ReminderType $type, int $days, string $emailUid, DateTime $windowStart, DateTime $windowEnd): array
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
					'reminders.days' => $days,
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

		$dueAfter = $this->getDueDateAt($type, $days, $windowStart);
		$dueBy = $this->getDueDateAt($type, $days, $windowEnd);
		$results = [];
		foreach ($orders as $order) {
			/** @var Account $account */
			$account = $plugin->getAccounts()->getAccountById((int) $accountIdsByOrderId[$order->id]);

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

			$results[] = $this->sendOrderReminder($type, $days, $emailUid, $order, $dateDue);
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

	private function sendOrderReminder(ReminderType $type, int $days, string $emailUid, Order $order, DateTime $dateDue): ?bool
	{
		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();
		$email = $commerce->getEmails()->getAllEnabledEmails($order->storeId)->firstWhere('uid', $emailUid);

		if (! $email instanceof CommerceEmail) {
			Craft::warning(sprintf('No %s reminder was sent for order %s, because its Commerce email isn’t enabled in the order’s store.', $type->value, $order->reference), Plugin::HANDLE);

			return false;
		}

		return $this->send($type, $days, [
			'orderId' => $order->id,
		], static function () use ($commerce, $email, $order, $type, $days, $dateDue): bool {
			$error = '';
			$sent = $commerce->getEmails()->sendEmail($email, $order, null, [
				'netTerms' => [
					'type' => $type->value,
					'days' => $days,
					'dateDue' => $dateDue,
					'amountDue' => Amounts::toString(Plugin::getInstance()->getCreditOrders()->getBalance($order)),
				],
			], $error);

			if (! $sent) {
				Craft::error(sprintf('The %s reminder for order %s was not sent: %s', $type->value, $order->reference, $error), Plugin::HANDLE);
			}

			return $sent;
		});
	}

	/**
	 * Send the reminder once, or return null when another run already claimed it.
	 *
	 * @param array{emailId?: int|null, invoiceId?: int|null, orderId?: int|null} $target
	 * @param callable(): bool $deliver
	 */
	private function send(ReminderType $type, int $days, array $target, callable $deliver): ?bool
	{
		$reminderRecord = new ReminderRecord();
		$reminderRecord->type = $type->value;
		$reminderRecord->days = $days;
		$reminderRecord->emailId = $target['emailId'] ?? null;
		$reminderRecord->invoiceId = $target['invoiceId'] ?? null;
		$reminderRecord->orderId = $target['orderId'] ?? null;

		// Write the row before sending, so the unique index stops a concurrent run sending the reminder twice
		try {
			$reminderRecord->save(false);
		} catch (IntegrityException) {
			// Leave the reminder out of this run's counts, since another run claimed it
			return null;
		}

		// Catch render errors too, since an admin's edit to an email can break its Twig
		try {
			$sent = $deliver();
		} catch (Throwable $throwable) {
			Craft::error(sprintf('The %s reminder for %s was not sent: %s', $type->value, isset($target['invoiceId']) ? "invoice {$target['invoiceId']}" : "order {$target['orderId']}", $throwable->getMessage()), Plugin::HANDLE);
			$sent = false;
		}

		if (! $sent) {
			$reminderRecord->delete();
		}

		return $sent;
	}
}
