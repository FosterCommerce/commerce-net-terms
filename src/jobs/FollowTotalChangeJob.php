<?php

declare(strict_types=1);

namespace fostercommerce\netterms\jobs;

use Craft;
use craft\errors\MutexException;
use craft\queue\BaseJob;
use fostercommerce\netterms\errors\AccountBusyException;
use fostercommerce\netterms\Plugin;
use yii\queue\RetryableJobInterface;

/**
 * Settles a staff edit to a credit order’s total, in invoice billing.
 */
class FollowTotalChangeJob extends BaseJob implements RetryableJobInterface
{
	private const MAX_ATTEMPTS = 5;

	public int $orderId;

	public string $change;

	/**
	 * @var string unique per edit, for retries
	 */
	public string $editKey;

	public ?int $authorId = null;

	public string $orderReference = '';

	public function execute($queue): void
	{
		Plugin::getInstance()->getCreditOrders()->followTotalChange($this->orderId, $this->change, $this->editKey, $this->authorId);
	}

	public function getTtr(): int
	{
		return 300;
	}

	/**
	 * Retry when another request holds the account or order lock, since the ledger doesn’t match the order’s total until the job succeeds.
	 */
	public function canRetry($attempt, $error): bool
	{
		return $attempt < self::MAX_ATTEMPTS && ($error instanceof AccountBusyException || $error instanceof MutexException);
	}

	protected function defaultDescription(): ?string
	{
		return Craft::t(Plugin::HANDLE, 'queue.followTotalChange', [
			'reference' => $this->orderReference,
		]);
	}
}
