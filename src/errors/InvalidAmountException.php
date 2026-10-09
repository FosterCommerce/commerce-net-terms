<?php

declare(strict_types=1);

namespace fostercommerce\netterms\errors;

use yii\base\UserException;

/**
 * An amount that can’t be used: typed input that isn’t an amount, or an order total raised past available credit. The message is safe to show staff.
 */
class InvalidAmountException extends UserException
{
}
