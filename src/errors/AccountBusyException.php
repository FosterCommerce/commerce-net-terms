<?php

declare(strict_types=1);

namespace fostercommerce\netterms\errors;

/**
 * Another request holds the account lock, so the write can be tried again.
 */
class AccountBusyException extends LedgerException
{
}
