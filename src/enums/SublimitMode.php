<?php

declare(strict_types=1);

namespace fostercommerce\netterms\enums;

use Craft;
use fostercommerce\netterms\Plugin;

enum SublimitMode: string
{
	case Ceiling = 'ceiling';

	case Reserved = 'reserved';

	public function label(): string
	{
		return match ($this) {
			self::Ceiling => Craft::t(Plugin::HANDLE, 'sublimitMode.ceiling'),
			self::Reserved => Craft::t(Plugin::HANDLE, 'sublimitMode.reserved'),
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
