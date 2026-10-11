<?php

declare(strict_types=1);

namespace fostercommerce\netterms\controllers;

use Craft;
use craft\helpers\App;
use craft\web\Controller;
use fostercommerce\netterms\enums\Billing;
use fostercommerce\netterms\enums\EmailTrigger;
use fostercommerce\netterms\models\Email;
use fostercommerce\netterms\Plugin;
use yii\web\ForbiddenHttpException;
use yii\web\NotFoundHttpException;
use yii\web\Response;

class EmailsController extends Controller
{
	use RequestParamsTrait;

	public function beforeAction($action): bool
	{
		if (! parent::beforeAction($action)) {
			return false;
		}

		$this->requireCpRequest();
		$this->requirePermission('accessPlugin-' . Plugin::HANDLE);
		$this->requirePermission(Plugin::PERMISSION_MANAGE_EMAILS);

		if (Plugin::getInstance()->getBilling() !== Billing::Invoices) {
			throw new ForbiddenHttpException(Craft::t(Plugin::HANDLE, 'error.invoiceBillingOnly'));
		}

		return true;
	}

	public function actionIndex(): Response
	{
		$emails = Plugin::getInstance()->getEmails();

		return $this->renderTemplate('net-terms/emails/_index', [
			'invoiceEmail' => $emails->getInvoiceEmail(),
			'reminderEmails' => $emails->getReminderEmails(),
		]);
	}

	public function actionEdit(?int $emailId = null, ?Email $email = null): Response
	{
		if (! $email instanceof Email) {
			$email = $emailId !== null ? Plugin::getInstance()->getEmails()->getEmailById($emailId) : new Email();
		}

		if (! $email instanceof Email) {
			throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'error.emailNotFound'));
		}

		$mailSettings = App::mailSettings();

		return $this->renderTemplate('net-terms/emails/_edit', [
			'email' => $email,
			'senderAddressPlaceholder' => App::parseEnv($mailSettings->fromEmail),
			'senderNamePlaceholder' => App::parseEnv($mailSettings->fromName),
		]);
	}

	public function actionSave(): ?Response
	{
		$this->requirePostRequest();

		$emails = Plugin::getInstance()->getEmails();
		$emailId = $this->getIdParam('emailId');
		$email = $emailId !== null ? $emails->getEmailById($emailId) : new Email();

		if (! $email instanceof Email) {
			throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'error.emailNotFound'));
		}

		// Keep the invoice email's trigger, since only reminders choose a schedule
		if ($email->getIsReminder()) {
			$email->trigger = $this->request->getBodyParam('trigger') === EmailTrigger::Overdue->value ? EmailTrigger::Overdue : EmailTrigger::DueSoon;
			$days = $this->request->getBodyParam('days');
			$email->days = is_numeric($days) ? (int) $days : null;
		}

		$email->name = $this->getTextParam('name');
		$email->enabled = (bool) $this->request->getBodyParam('enabled');
		$recipients = $this->request->getBodyParam('recipients');
		$email->sendToHolder = is_array($recipients) && in_array('holder', $recipients, true);
		$email->sendToBuyers = is_array($recipients) && in_array('buyers', $recipients, true);
		$email->ccAddresses = $this->getOptionalTextParam('ccAddresses');
		$email->bccAddresses = $this->getOptionalTextParam('bccAddresses');
		$email->replyTo = $this->getOptionalTextParam('replyTo');
		$email->senderName = $this->getOptionalTextParam('senderName');
		$email->senderAddress = $this->getOptionalTextParam('senderAddress');
		$email->subject = $this->getTextParam('subject');
		$email->body = $this->getTextParam('body');
		$email->attachPdf = (bool) $this->request->getBodyParam('attachPdf');

		if (! $emails->saveEmail($email)) {
			return $this->asModelFailure($email, Craft::t(Plugin::HANDLE, 'email.couldNotSave'), 'email');
		}

		return $this->asModelSuccess($email, Craft::t(Plugin::HANDLE, 'email.saved'), 'email', [], 'net-terms/emails');
	}

	public function actionDelete(): ?Response
	{
		$this->requirePostRequest();

		$emails = Plugin::getInstance()->getEmails();
		$email = $emails->getEmailById($this->requireIdParam('emailId'));

		if (! $email instanceof Email || ! $emails->deleteEmail($email)) {
			return $this->asFailure(Craft::t(Plugin::HANDLE, 'email.couldNotDelete'));
		}

		return $this->asSuccess(Craft::t(Plugin::HANDLE, 'email.deleted'), [], 'net-terms/emails');
	}
}
