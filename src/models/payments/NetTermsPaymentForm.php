<?php

declare(strict_types=1);

namespace fostercommerce\netterms\models\payments;

use craft\commerce\models\payments\BasePaymentForm;

class NetTermsPaymentForm extends BasePaymentForm
{
	/**
	 * A value that isn't an account ID charges the customer’s only account in the order’s store.
	 */
	public mixed $accountId = null;
}
