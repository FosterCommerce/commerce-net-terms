<?php

declare(strict_types=1);

namespace fostercommerce\netterms\console\controllers;

use Craft;
use craft\console\Controller;
use craft\helpers\Console;
use fostercommerce\netterms\enums\Billing;
use fostercommerce\netterms\errors\LedgerException;
use fostercommerce\netterms\models\Account;
use fostercommerce\netterms\models\Invoice;
use fostercommerce\netterms\Plugin;
use yii\console\ExitCode;

/**
 * Issues invoices from the command line, for running on a schedule.
 */
class InvoicesController extends Controller
{
	/**
	 * @var int|null Issue for this account only. Every account when omitted.
	 */
	public ?int $accountId = null;

	/**
	 * @var bool Email each invoice to its account holder once issued.
	 */
	public bool $send = false;

	/**
	 * @return string[]
	 */
	public function options($actionID): array
	{
		return [...parent::options($actionID), 'accountId', 'send'];
	}

	/**
	 * Bill every uninvoiced charge, one invoice per account.
	 */
	public function actionIssue(): int
	{
		$plugin = Plugin::getInstance();

		if ($plugin->getBilling() !== Billing::Invoices) {
			$this->stderr(Craft::t(Plugin::HANDLE, 'error.invoiceBillingOnly') . "\n", Console::FG_RED);

			return ExitCode::UNAVAILABLE;
		}

		if ($this->accountId !== null) {
			$account = $plugin->getAccounts()->getAccountById($this->accountId);
			if (! $account instanceof Account) {
				$this->stderr("No account has the ID {$this->accountId}.\n", Console::FG_RED);

				return ExitCode::DATAERR;
			}

			$accounts = [$account];
		} else {
			// Suspended accounts are included, since suspending stops new charges but not what is owed
			$accounts = $plugin->getAccounts()->getAllAccounts();
		}

		$exitCode = ExitCode::OK;

		// Issue the remaining accounts when one is locked
		foreach ($accounts as $account) {
			try {
				$invoice = $plugin->getInvoices()->issueInvoice($account);
			} catch (LedgerException $ledgerException) {
				$this->stderr("Account {$account->id}: {$ledgerException->getMessage()}\n", Console::FG_RED);
				$exitCode = ExitCode::TEMPFAIL;

				continue;
			}

			if (! $invoice instanceof Invoice) {
				$this->stdout("Account {$account->id}: nothing to invoice.\n");

				continue;
			}

			$this->stdout("Account {$account->id}: issued {$invoice->number}.\n", Console::FG_GREEN);

			if ($this->send && ! $plugin->getInvoices()->sendInvoice($invoice)) {
				$this->stderr("Account {$account->id}: {$invoice->number} was not emailed.\n", Console::FG_RED);
				$exitCode = ExitCode::UNSPECIFIED_ERROR;
			}
		}

		return $exitCode;
	}
}
