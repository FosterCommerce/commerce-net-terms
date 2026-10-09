<?php

declare(strict_types=1);

namespace fostercommerce\netterms\enums;

enum ReminderType: string
{
	case DueSoon = 'dueSoon';

	case Overdue = 'overdue';

	/**
	 * The system message key the reminder is sent from.
	 */
	public function messageKey(): string
	{
		return match ($this) {
			self::DueSoon => 'net_terms_due_soon',
			self::Overdue => 'net_terms_overdue',
		};
	}
}
