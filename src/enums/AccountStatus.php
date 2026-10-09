<?php

declare(strict_types=1);

namespace fostercommerce\netterms\enums;

use Craft;
use fostercommerce\netterms\Plugin;

enum AccountStatus: string
{
	case Active = 'active';

	case Suspended = 'suspended';

	public function label(): string
	{
		return match ($this) {
			self::Active => Craft::t(Plugin::HANDLE, 'accountStatus.active'),
			self::Suspended => Craft::t(Plugin::HANDLE, 'accountStatus.suspended'),
		};
	}

	public function color(): string
	{
		return match ($this) {
			self::Active => 'green',
			self::Suspended => 'red',
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
