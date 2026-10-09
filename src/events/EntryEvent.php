<?php

declare(strict_types=1);

namespace fostercommerce\netterms\events;

use fostercommerce\netterms\models\Entry;
use yii\base\Event;

class EntryEvent extends Event
{
	public Entry $entry;
}
