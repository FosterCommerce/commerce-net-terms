<?php

declare(strict_types=1);

namespace fostercommerce\netterms\models\responses;

use craft\commerce\base\RequestResponseInterface;

/**
 * The result of charging or refunding an account. Settled immediately, so there is never a redirect.
 */
class NetTermsResponse implements RequestResponseInterface
{
	public function __construct(
		private readonly bool $successful,
		private readonly string $message = '',
		private readonly string $reference = '',
	) {
	}

	public static function success(string $reference): self
	{
		return new self(true, '', $reference);
	}

	public static function declined(string $message): self
	{
		return new self(false, $message);
	}

	public function isSuccessful(): bool
	{
		return $this->successful;
	}

	public function isProcessing(): bool
	{
		return false;
	}

	public function isRedirect(): bool
	{
		return false;
	}

	public function getRedirectMethod(): string
	{
		return '';
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getRedirectData(): array
	{
		return [];
	}

	public function getRedirectUrl(): string
	{
		return '';
	}

	public function getTransactionReference(): string
	{
		return $this->reference;
	}

	public function getCode(): string
	{
		return $this->successful ? 'success' : 'declined';
	}

	public function getData(): mixed
	{
		return [];
	}

	public function getMessage(): string
	{
		return $this->message;
	}

	public function redirect(): void
	{
	}
}
