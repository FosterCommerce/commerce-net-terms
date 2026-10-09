<?php

declare(strict_types=1);

namespace fostercommerce\netterms\errors;

use yii\base\UserException;

/**
 * A ledger write was refused. The message is safe to show the person who asked for it.
 */
class LedgerException extends UserException
{
}
