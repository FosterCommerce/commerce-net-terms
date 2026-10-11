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
	 * Email each reminder whose moment is today and that hasn't been sent. A missed day's reminder isn't sent later.
	 */
	public function actionSend(): int
	{
		$counts = Plugin::getInstance()->getReminders()->sendReminders(new DateTime('today'), new DateTime('tomorrow'));

		$this->stdout("Reminders sent: {$counts['sent']}.\n", Console::FG_GREEN);

		if ($counts['failed'] > 0) {
			$this->stderr("Reminders that failed: {$counts['failed']}. See the log.\n", Console::FG_RED);

			return ExitCode::UNSPECIFIED_ERROR;
		}

		return ExitCode::OK;
	}
}
