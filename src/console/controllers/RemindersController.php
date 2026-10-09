<?php

declare(strict_types=1);

namespace fostercommerce\netterms\console\controllers;

use craft\console\Controller;
use craft\helpers\Console;
use DateTime;
use fostercommerce\netterms\Plugin;
use yii\console\ExitCode;

/**
 * Sends payment reminders from the command line, for running on a schedule.
 */
class RemindersController extends Controller
{
	/**
	 * Two days covers one missed daily run. The reminders table’s unique index stops a second send where runs overlap.
	 */
	private const LOOKBACK = '-2 days';

	/**
	 * Email each due-soon and overdue reminder whose moment came in the last two days and that hasn't been sent.
	 */
	public function actionSend(): int
	{
		$now = new DateTime();
		$counts = Plugin::getInstance()->getReminders()->sendReminders((clone $now)->modify(self::LOOKBACK), $now);

		$this->stdout("Reminders sent: {$counts['sent']}.\n", Console::FG_GREEN);

		if ($counts['failed'] > 0) {
			$this->stderr("Reminders that failed: {$counts['failed']}. See the log.\n", Console::FG_RED);

			return ExitCode::UNSPECIFIED_ERROR;
		}

		return ExitCode::OK;
	}
}
