<?php

declare(strict_types=1);

namespace fostercommerce\netterms\fields;

use Craft;
use craft\base\ElementInterface;
use craft\base\Field;
use craft\helpers\Cp;
use fostercommerce\netterms\models\Account;
use fostercommerce\netterms\Plugin;
use GraphQL\Type\Definition\Type;
use yii\db\Schema;

/**
 * Links an element, such as a company entry, to one Net Terms account.
 */
class NetTermsAccount extends Field
{
	public static function displayName(): string
	{
		return Craft::t(Plugin::HANDLE, 'field.netTermsAccount');
	}

	public static function icon(): string
	{
		return 'credit-card';
	}

	public static function phpType(): string
	{
		return sprintf('\\%s|null', Account::class);
	}

	public static function dbType(): string
	{
		return Schema::TYPE_INTEGER;
	}

	public function normalizeValue(mixed $value, ?ElementInterface $element): mixed
	{
		if ($value instanceof Account) {
			return $value;
		}

		return is_numeric($value) ? Plugin::getInstance()->getAccounts()->getAccountById((int) $value) : null;
	}

	public function serializeValue(mixed $value, ?ElementInterface $element): mixed
	{
		return $value instanceof Account ? $value->id : null;
	}

	/**
	 * Expose the account ID, since an `Account` isn't a GraphQL type.
	 *
	 * @return array<string, mixed>
	 */
	public function getContentGqlType(): Type|array
	{
		return [
			'name' => $this->handle,
			'type' => Type::int(),
			'resolve' => function (ElementInterface $source): ?int {
				$account = $source->getFieldValue((string) $this->handle);

				return $account instanceof Account ? $account->id : null;
			},
		];
	}

	protected function inputHtml(mixed $value, ?ElementInterface $element, bool $inline = false): string
	{
		$options = [[
			'label' => ' ',
			'value' => '__blank__',
		]];
		foreach (Plugin::getInstance()->getAccounts()->getAllAccounts() as $account) {
			$options[] = [
				'label' => $account->title,
				'value' => $account->id,
			];
		}

		return Cp::selectizeHtml([
			'id' => $this->getInputId(),
			'describedBy' => $this->describedBy,
			'name' => $this->handle,
			'options' => $options,
			'value' => $value instanceof Account ? $value->id : null,
		]);
	}

	protected function searchKeywords(mixed $value, ElementInterface $element): string
	{
		return $value instanceof Account ? (string) $value->title : '';
	}
}
