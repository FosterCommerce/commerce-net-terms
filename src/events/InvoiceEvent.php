<?php

declare(strict_types=1);

namespace fostercommerce\netterms\events;

use fostercommerce\netterms\models\Invoice;
use yii\base\Event;

class InvoiceEvent extends Event
{
	public Invoice $invoice;
}
