<?php

declare(strict_types=1);

namespace fostercommerce\netterms\models;

use Craft;
use craft\base\Model;
use fostercommerce\netterms\enums\EmailTrigger;
use fostercommerce\netterms\Plugin;

/**
 * An invoice-billing email: the invoice email, or a reminder before or after an invoice is due.
 */
class Email extends Model
{
	public ?int $id = null;

	public string $name = '';

	public EmailTrigger $trigger = EmailTrigger::DueSoon;

	public ?int $days = null;

	public bool $enabled = true;

	public bool $sendToHolder = true;

	public bool $sendToBuyers = false;

	public ?string $ccAddresses = null;

	public ?string $bccAddresses = null;

	public ?string $replyTo = null;

	public ?string $senderName = null;

	public ?string $senderAddress = null;

	public string $subject = '';

	public string $body = '';

	public bool $attachPdf = false;

	public function getIsReminder(): bool
	{
		return $this->trigger !== EmailTrigger::Issued;
	}

	public function validateDays(string $attribute): void
	{
		if (! $this->getIsReminder()) {
			return;
		}

		$minimumDays = $this->trigger === EmailTrigger::DueSoon ? 1 : 0;

		if ($this->days === null || $this->days < $minimumDays) {
			$this->addError($attribute, Craft::t(Plugin::HANDLE, 'settings.reminderInvalid'));
		}
	}

	public function validateRecipients(string $attribute): void
	{
		if (! $this->sendToHolder && ! $this->sendToBuyers) {
			$this->addError($attribute, Craft::t(Plugin::HANDLE, 'email.recipientsRequired'));
		}
	}

	/**
	 * @return array<int, mixed>
	 */
	protected function defineRules(): array
	{
		return [
			[['name', 'subject', 'body'], 'required'],
			[['name', 'replyTo', 'senderName', 'senderAddress'],
				'string',
				'max' => 255],
			[['days'],
				'validateDays',
				'skipOnEmpty' => false],
			[['sendToHolder'],
				'validateRecipients',
				'skipOnEmpty' => false],
		];
	}
}
