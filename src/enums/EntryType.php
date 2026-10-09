<?php

declare(strict_types=1);

namespace fostercommerce\netterms\enums;

use Craft;
use fostercommerce\netterms\Plugin;

enum EntryType: string
{
	case Charge = 'charge';

	case Refund = 'refund';

	case Adjustment = 'adjustment';

	case Payment = 'payment';

	case Reversal = 'reversal';

	case OrderChange = 'orderChange';

	public function label(): string
	{
		return match ($this) {
			self::Charge => Craft::t(Plugin::HANDLE, 'entryType.charge'),
			self::Refund => Craft::t(Plugin::HANDLE, 'entryType.refund'),
			self::Adjustment => Craft::t(Plugin::HANDLE, 'entryType.adjustment'),
			self::Payment => Craft::t(Plugin::HANDLE, 'entryType.payment'),
			self::Reversal => Craft::t(Plugin::HANDLE, 'entryType.reversal'),
			self::OrderChange => Craft::t(Plugin::HANDLE, 'entryType.orderChange'),
		};
	}

	/**
	 * Whether an invoice bills this entry, as opposed to settling one.
	 */
	public function isInvoiced(): bool
	{
		return in_array($this, [self::Charge, self::Refund, self::Adjustment, self::OrderChange], true);
	}
}
