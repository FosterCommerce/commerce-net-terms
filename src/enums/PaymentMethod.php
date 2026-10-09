<?php

declare(strict_types=1);

namespace fostercommerce\netterms\enums;

use Craft;
use fostercommerce\netterms\Plugin;

enum PaymentMethod: string
{
	case Card = 'card';

	case Check = 'check';

	case Ach = 'ach';

	case Wire = 'wire';

	case Other = 'other';

	public function label(): string
	{
		return match ($this) {
			self::Card => Craft::t(Plugin::HANDLE, 'paymentMethod.card'),
			self::Check => Craft::t(Plugin::HANDLE, 'paymentMethod.check'),
			self::Ach => Craft::t(Plugin::HANDLE, 'paymentMethod.ach'),
			self::Wire => Craft::t(Plugin::HANDLE, 'paymentMethod.wire'),
			self::Other => Craft::t(Plugin::HANDLE, 'paymentMethod.other'),
		};
	}

	/**
	 * @return array<string, string>
	 */
	public static function labelMap(): array
	{
		$labels = [];
		foreach (self::cases() as $case) {
			$labels[$case->value] = $case->label();
		}

		return $labels;
	}
}
