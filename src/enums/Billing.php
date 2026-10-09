<?php

declare(strict_types=1);

namespace fostercommerce\netterms\enums;

/**
 * What Net Terms bills: invoices the plugin issues, or the orders themselves.
 */
enum Billing: string
{
	case Invoices = 'invoices';

	case Orders = 'orders';
}
