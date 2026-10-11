<?php

declare(strict_types=1);

namespace fostercommerce\netterms\services;

use Craft;
use craft\enums\CmsEdition;
use craft\helpers\App;
use craft\helpers\Markdown;
use craft\helpers\Template;
use craft\mail\Message;
use craft\web\View;
use DateTime;
use fostercommerce\netterms\enums\EmailTrigger;
use fostercommerce\netterms\helpers\Amounts;
use fostercommerce\netterms\models\Email;
use fostercommerce\netterms\models\Invoice;
use fostercommerce\netterms\models\InvoiceLine;
use fostercommerce\netterms\Plugin;
use fostercommerce\netterms\records\EmailRecord;
use Throwable;
use yii\base\Component;

/**
 * The emails sent in invoice billing, which admins edit in the control panel.
 */
class Emails extends Component
{
	public function getEmailById(int $id): ?Email
	{
		$record = EmailRecord::findOne($id);

		return $record instanceof EmailRecord ? $this->createEmailFromRecord($record) : null;
	}

	public function getInvoiceEmail(): ?Email
	{
		$record = EmailRecord::findOne([
			'trigger' => EmailTrigger::Issued->value,
		]);

		return $record instanceof EmailRecord ? $this->createEmailFromRecord($record) : null;
	}

	/**
	 * Reminders in the order they go out.
	 *
	 * @return Email[]
	 */
	public function getReminderEmails(): array
	{
		/** @var EmailRecord[] $records */
		$records = EmailRecord::find()
			->where([
				'not',
				[
					'trigger' => EmailTrigger::Issued->value,
				],
			])
			->all();

		$emails = array_map($this->createEmailFromRecord(...), $records);

		// Sort by days relative to the due date, negative before it
		$offset = static fn (Email $email): int => $email->trigger === EmailTrigger::DueSoon ? -(int) $email->days : (int) $email->days;
		usort($emails, static fn (Email $first, Email $second): int => [$offset($first), $first->id] <=> [$offset($second), $second->id]);

		return $emails;
	}

	public function saveEmail(Email $email): bool
	{
		if (! $email->validate()) {
			return false;
		}

		$record = $email->id !== null ? EmailRecord::findOne($email->id) : new EmailRecord();
		if (! $record instanceof EmailRecord) {
			return false;
		}

		$record->name = $email->name;
		$record->trigger = $email->trigger->value;
		$record->days = $email->getIsReminder() ? $email->days : null;
		$record->enabled = $email->enabled;
		$record->sendToHolder = $email->sendToHolder;
		$record->sendToBuyers = $email->sendToBuyers;
		$record->ccAddresses = $email->ccAddresses;
		$record->bccAddresses = $email->bccAddresses;
		$record->replyTo = $email->replyTo;
		$record->senderName = $email->senderName;
		$record->senderAddress = $email->senderAddress;
		$record->subject = $email->subject;
		$record->body = $email->body;
		$record->attachPdf = $email->attachPdf;
		$record->save(false);
		$email->id = $record->id;

		return true;
	}

	/**
	 * Delete a reminder. The invoice email can be turned off but not deleted.
	 */
	public function deleteEmail(Email $email): bool
	{
		if (! $email->getIsReminder()) {
			return false;
		}

		return (bool) EmailRecord::findOne($email->id)?->delete();
	}

	/**
	 * Email an invoice with this email's content and recipients. Attaches the email's PDF when it renders, and sends without it when it doesn't.
	 */
	public function send(Email $email, Invoice $invoice): bool
	{
		$invoices = Plugin::getInstance()->getInvoices();
		$holder = $invoice->getAccount()->getHolder();
		$recipients = $this->getRecipients($email, $invoice);

		/** @var DateTime $dateDue */
		$dateDue = $invoice->dateDue;
		$variables = [
			'recipientName' => $holder?->getName(),
			'label' => Craft::t(Plugin::HANDLE, 'reminder.invoiceLabel', [
				'number' => $invoice->number,
			]),
			'amountDue' => Amounts::toString($invoices->getBalance($invoice)),
			'dateDue' => Craft::$app->getFormatter()->asDate($dateDue, 'long'),
			'link' => $invoices->getStorefrontUrl($invoice),
			'days' => $email->days,
			'notes' => $invoice->notes,
			'lines' => array_map(static fn (InvoiceLine $line): array => [
				'buyer' => $line->getBuyer()?->getName() ?? Craft::t(Plugin::HANDLE, 'ledger.account'),
				'amount' => Amounts::toString($invoices->getLineAmount($line)),
			], $invoice->getLines()),
		];

		// Catch render errors too, since an admin's edit to the email can break its Twig
		try {
			$message = $this->compose($email, $variables)
				->setTo($recipients)
				->setCc($this->renderAddresses($email->ccAddresses, $variables))
				->setBcc($this->renderAddresses($email->bccAddresses, $variables));
		} catch (Throwable $throwable) {
			Craft::error(sprintf('The “%s” email for invoice %s was not sent: %s', $email->name, $invoice->number, $throwable->getMessage()), Plugin::HANDLE);

			return false;
		}

		if ($message->getTo() === []) {
			Craft::warning(sprintf('The “%s” email for invoice %s has no recipient with an email address.', $email->name, $invoice->number), Plugin::HANDLE);

			return false;
		}

		if ($email->attachPdf) {
			try {
				$pdf = $invoices->renderPdf($invoice);
				$message->attachContent($pdf['content'], [
					'fileName' => $pdf['fileName'],
					'contentType' => 'application/pdf',
				]);
			} catch (Throwable $throwable) {
				Craft::error(sprintf('The “%s” email for invoice %s was sent without its PDF: %s', $email->name, $invoice->number, $throwable->getMessage()), Plugin::HANDLE);
			}
		}

		return $message->send();
	}

	/**
	 * Add the invoice email and the given reminders, so admins start from editable text rather than an empty list.
	 *
	 * @param array<int, array{trigger: EmailTrigger, days: int, enabled: bool}> $reminders defaults to a due-soon and an overdue reminder, turned off
	 */
	public function createStarterEmails(?array $reminders = null): void
	{
		$reminders ??= [
			[
				'trigger' => EmailTrigger::DueSoon,
				'days' => 7,
				'enabled' => false,
			],
			[
				'trigger' => EmailTrigger::Overdue,
				'days' => 1,
				'enabled' => false,
			],
		];

		foreach ([
			[
				'trigger' => EmailTrigger::Issued,
				'days' => null,
				'enabled' => true,
			],
			...$reminders,
		] as $starter) {
			$trigger = $starter['trigger'];
			$this->saveEmail(new Email([
				'name' => Craft::t(Plugin::HANDLE, "email.starter.{$trigger->value}.name"),
				'attachPdf' => $trigger === EmailTrigger::Issued,
				'trigger' => $trigger,
				'days' => $starter['days'],
				'enabled' => $starter['enabled'],
				'subject' => Craft::t(Plugin::HANDLE, "email.starter.{$trigger->value}.subject"),
				'body' => Craft::t(Plugin::HANDLE, "email.starter.{$trigger->value}.body"),
			]));
		}
	}

	private function createEmailFromRecord(EmailRecord $record): Email
	{
		return new Email([
			'id' => $record->id,
			'name' => $record->name,
			'trigger' => EmailTrigger::from($record->trigger),
			'days' => $record->days,
			'enabled' => (bool) $record->enabled,
			'sendToHolder' => (bool) $record->sendToHolder,
			'sendToBuyers' => (bool) $record->sendToBuyers,
			'ccAddresses' => $record->ccAddresses,
			'bccAddresses' => $record->bccAddresses,
			'replyTo' => $record->replyTo,
			'senderName' => $record->senderName,
			'senderAddress' => $record->senderAddress,
			'subject' => $record->subject,
			'body' => $record->body,
			'attachPdf' => (bool) $record->attachPdf,
		]);
	}

	/**
	 * @return string[]
	 */
	private function getRecipients(Email $email, Invoice $invoice): array
	{
		$recipients = [];

		if ($email->sendToHolder) {
			$recipients[] = $invoice->getAccount()->getHolder()?->email;
		}

		if ($email->sendToBuyers) {
			foreach ($invoice->getLines() as $line) {
				$recipients[] = $line->getBuyer()?->getUser()?->email;
			}
		}

		return array_values(array_unique(array_filter($recipients)));
	}

	/**
	 * Render the subject and body the way Craft renders a system message, inside the site's HTML email template.
	 *
	 * @param array<string, mixed> $variables
	 */
	private function compose(Email $email, array $variables): Message
	{
		$view = Craft::$app->getView();
		$mailer = Craft::$app->getMailer();
		$templateMode = $view->getTemplateMode();
		$view->setTemplateMode(View::TEMPLATE_MODE_SITE);

		// Pass the variables Craft passes to its own emails, since its default HTML template reads `language`
		$mailSettings = App::mailSettings();
		$variables += [
			'fromEmail' => App::parseEnv($mailSettings->fromEmail),
			'replyToEmail' => App::parseEnv($mailSettings->replyToEmail),
			'fromName' => App::parseEnv($mailSettings->fromName),
			'language' => Craft::$app->getRequest()->getIsSiteRequest() ? Craft::$app->language : Craft::$app->getSites()->getPrimarySite()->language,
		];

		$html = null;
		try {
			$subject = $view->renderSandboxedString($email->subject, $variables);
			// Unwrap <https://...> links, as Craft does, so the text body doesn't read them as tags
			$textBody = (string) preg_replace('/<(https?:\/\/.+?)>/', '$1', (string) $view->renderSandboxedString($email->body, $variables));
			$htmlBody = $view->renderSandboxedString($email->body, $variables, escapeHtml: true);
			$hasSiteTemplate = Craft::$app->edition->value >= CmsEdition::Pro->value && $mailer->template;

			// Send the text body alone when the HTML email template fails, as Craft does for its own emails
			try {
				$html = $view->renderTemplate($hasSiteTemplate ? (string) $mailer->template : '_special/email.twig', [
					...$variables,
					'body' => Template::raw(Markdown::process($htmlBody, 'gfm-comment')),
				], $hasSiteTemplate ? View::TEMPLATE_MODE_SITE : View::TEMPLATE_MODE_CP);
			} catch (Throwable $throwable) {
				Craft::warning(sprintf('The “%s” email was sent without HTML, since the HTML email template failed: %s', $email->name, $throwable->getMessage()), Plugin::HANDLE);
			}
		} finally {
			$view->setTemplateMode($templateMode);
		}

		/** @var Message $message */
		$message = $mailer->compose();
		$message->setSubject($subject)
			->setTextBody($textBody);

		if ($html !== null) {
			$message->setHtmlBody($html);
		}

		// Leave the sender unset without an override, so the mailer applies the site's own email settings
		if ($email->senderAddress !== null || $email->senderName !== null) {
			$message->setFrom([
				(string) (App::parseEnv($email->senderAddress) ?: App::parseEnv($mailSettings->fromEmail)) => (string) (App::parseEnv($email->senderName) ?: App::parseEnv($mailSettings->fromName)),
			]);
		}

		if ($email->replyTo !== null) {
			$message->setReplyTo($view->renderSandboxedString($email->replyTo, $variables));
		}

		return $message;
	}

	/**
	 * Comma-separated addresses after environment variables and Twig, as Commerce reads its recipient fields.
	 *
	 * @param array<string, mixed> $variables
	 * @return string[]
	 */
	private function renderAddresses(?string $addresses, array $variables): array
	{
		if ($addresses === null) {
			return [];
		}

		$renderedAddresses = Craft::$app->getView()->renderSandboxedString((string) App::parseEnv($addresses), $variables);

		return array_values(array_filter(array_map(trim(...), explode(',', (string) $renderedAddresses))));
	}
}
