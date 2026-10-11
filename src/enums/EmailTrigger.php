<?php

declare(strict_types=1);

namespace fostercommerce\netterms\enums;

enum EmailTrigger: string
{
	case Issued = 'issued';

	case DueSoon = 'dueSoon';

	case Overdue = 'overdue';
}
