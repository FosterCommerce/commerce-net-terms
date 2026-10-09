<?php

declare(strict_types=1);

namespace fostercommerce\netterms\enums;

use Craft;
use fostercommerce\netterms\Plugin;

enum InvoiceStatus: string
{
	case Open = 'open';

	case PartiallyPaid = 'partiallyPaid';

	case Paid = 'paid';

	case Voided = 'voided';

	public function label(): string
	{
		return match ($this) {
			self::Open => Craft::t(Plugin::HANDLE, 'invoiceStatus.open'),
			self::PartiallyPaid => Craft::t(Plugin::HANDLE, 'invoiceStatus.partiallyPaid'),
			self::Paid => Craft::t(Plugin::HANDLE, 'invoiceStatus.paid'),
			self::Voided => Craft::t(Plugin::HANDLE, 'invoiceStatus.voided'),
		};
	}

	public function color(): string
	{
		return match ($this) {
			self::Open => 'orange',
			self::PartiallyPaid => 'blue',
			self::Paid => 'green',
			self::Voided => 'gray',
		};
	}
}
