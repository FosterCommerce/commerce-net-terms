<?php

declare(strict_types=1);

namespace fostercommerce\netterms\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\commerce\elements\Order;
use craft\web\View;
use fostercommerce\netterms\models\Account;
use fostercommerce\netterms\Plugin;
use yii\db\Schema;

/**
 * An order’s own payment terms in days, replacing the account’s terms for that order in order billing.
 */
class PaymentTerms extends Field
{
	public static function displayName(): string
	{
		return Craft::t(Plugin::HANDLE, 'field.paymentTerms');
	}

	public static function icon(): string
	{
		return 'calendar';
	}

	public static function phpType(): string
	{
		return 'int|string|null';
	}

	public static function dbType(): string
	{
		return Schema::TYPE_INTEGER;
	}

	/**
	 * Keep input that isn't a whole number as typed, so validation refuses it with a message rather than reading it as blank.
	 */
	public function normalizeValue(mixed $value, ?ElementInterface $element): mixed
	{
		if ($value === null || $value === '') {
			return null;
		}

		$days = filter_var($value, FILTER_VALIDATE_INT);

		return $days !== false ? $days : $value;
	}

	/**
	 * Keep the saved value unless the user can edit orders, since a customer can post this field with their own cart.
	 */
	public function normalizeValueFromRequest(mixed $value, ?ElementInterface $element): mixed
	{
		if (! Craft::$app->getUser()->checkPermission('commerce-editOrders')) {
			return $this->normalizeValue($element?->getFieldValue((string) $this->handle), $element);
		}

		return $this->normalizeValue($value, $element);
	}

	/**
	 * @return array<int, mixed>
	 */
	public function getElementValidationRules(): array
	{
		return [
			[
				'integer',
				'min' => 0,
			],
		];
	}

	protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline = false): string
	{
		// Prefer the account the order is linked to, since the staff member editing it isn't the buyer
		$account = $element instanceof Order
			? Plugin::getInstance()->getCreditOrders()->getAccountForOrder($element) ?? Plugin::getInstance()->getAccounts()->getAccountForOrder($element)
			: null;

		return Craft::$app->getView()->renderTemplate('net-terms/_fields/payment-terms', [
			'field' => $this,
			'value' => $value,
			'defaultTerms' => $account instanceof Account ? $account->getEffectivePaymentTerms() : Plugin::getInstance()->getSettings()->defaultPaymentTerms,
		], View::TEMPLATE_MODE_CP);
	}
}
