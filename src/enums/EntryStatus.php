<?php

declare(strict_types=1);

namespace fostercommerce\netterms\enums;

use Craft;
use fostercommerce\netterms\Plugin;

enum EntryStatus: string
{
	case Posted = 'posted';
	case Authorized = 'authorized';
	case Captured = 'captured';
	case Released = 'released';

	public function label(): string
	{
		return Craft::t(Plugin::HANDLE, 'entryStatus.' . $this->value);
	}

	public function isPosted(): bool
	{
		return $this === self::Posted || $this === self::Captured;
	}

	/**
	 * @return list<string>
	 */
	public static function postedValues(): array
	{
		return [self::Posted->value, self::Captured->value];
	}
}
