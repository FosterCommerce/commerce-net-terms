<?php

declare(strict_types=1);

namespace fostercommerce\netterms\elements;

use Craft;
use craft\elements\deletionblockers\BaseDeletionBlocker;
use fostercommerce\netterms\Plugin;

/**
 * Stops the users index deleting users who hold an account or have ledger history, and says why.
 */
class LedgerUsersBlocker extends BaseDeletionBlocker
{
	/**
	 * @var int[]
	 */
	private array $ledgerUserIds = [];

	public function init(): void
	{
		$accounts = Plugin::getInstance()->getAccounts();
		$this->ledgerUserIds = array_values(array_filter(
			array_map(intval(...), $this->elements->ids()->all()),
			$accounts->hasLedgerHistory(...),
		));

		parent::init();
	}

	public function isActive(): bool
	{
		return $this->ledgerUserIds !== [];
	}

	public function getSummary(): string
	{
		return Craft::t(Plugin::HANDLE, 'user.ledgerHistoryCantDelete', [
			'count' => count($this->ledgerUserIds),
		]);
	}

	/**
	 * @return array<int, array<string, mixed>>
	 */
	public function getActions(): array
	{
		return [];
	}
}
