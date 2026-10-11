<?php

declare(strict_types=1);

namespace fostercommerce\netterms\enums;

enum ReminderType: string
{
	case DueSoon = 'dueSoon';

	case Overdue = 'overdue';
}
