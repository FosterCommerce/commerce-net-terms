<?php

declare(strict_types=1);

namespace fostercommerce\netterms\elements;

use Craft;
use craft\elements\deletionblockers\BaseDeletionBlocker;
use fostercommerce\netterms\Plugin;

/**
 * Stops the orders index deleting orders on an issued invoice, and says why.
 */
class InvoicedOrdersBlocker extends BaseDeletionBlocker
{
	/**
	 * @var int[]
	 */
	private array $invoicedOrderIds = [];

	public function init(): void
	{
		$this->invoicedOrderIds = Plugin::getInstance()->getLedger()->getInvoicedOrderIds(array_map(intval(...), $this->elements->ids()->all()));

		parent::init();
	}

	public function isActive(): bool
	{
		return $this->invoicedOrderIds !== [];
	}

	public function getSummary(): string
	{
		return Craft::t(Plugin::HANDLE, 'order.invoicedCantDelete', [
			'count' => count($this->invoicedOrderIds),
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
