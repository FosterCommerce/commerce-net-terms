<?php

declare(strict_types=1);

namespace fostercommerce\netterms\helpers;

use Craft;
use craft\helpers\MoneyHelper;
use fostercommerce\netterms\errors\InvalidAmountException;
use fostercommerce\netterms\Plugin;
use Money\Currency;
use Money\Exception\ParserException;
use Money\Money;

/**
 * Converts between stored decimal strings, typed input and Money.
 */
final class Amounts
{
	public static function toMoney(string $decimal, Currency $currency): Money
	{
		/** @var Money $money */
		$money = MoneyHelper::toMoney([
			'value' => $decimal,
			'currency' => $currency,
		]);

		return $money;
	}

	/**
	 * Read what a SQL `SUM()` returns, which is null when no rows match.
	 */
	public static function fromSum(mixed $sum, Currency $currency): Money
	{
		return self::toMoney(is_numeric($sum) ? (string) $sum : '0', $currency);
	}

	/**
	 * Read grouped SQL sums keyed by ID, such as the rows of `Query::pairs()`.
	 *
	 * @param array<int|string, mixed> $sumsById
	 * @return array<int, Money>
	 */
	public static function fromSums(array $sumsById, Currency $currency): array
	{
		$amountsById = [];
		foreach ($sumsById as $id => $sum) {
			$amountsById[(int) $id] = self::fromSum($sum, $currency);
		}

		return $amountsById;
	}

	/**
	 * Parse an amount a person typed, in their locale. Returns null for blank input.
	 *
	 * @throws InvalidAmountException if the input isn’t a number
	 */
	public static function fromInput(string $input, Currency $currency, string $locale): ?Money
	{
		if (trim($input) === '') {
			return null;
		}

		try {
			/** @var Money $money */
			$money = MoneyHelper::toMoney([
				'value' => $input,
				'currency' => $currency,
				'locale' => $locale,
			]);
		} catch (ParserException) {
			throw new InvalidAmountException(Craft::t(Plugin::HANDLE, 'amount.invalid', [
				'input' => $input,
			]));
		}

		return $money;
	}

	/**
	 * Parse what a Craft money input posts: its `value` and the `locale` it was typed in. Returns null for blank input.
	 *
	 * @throws InvalidAmountException if the input isn’t a number
	 */
	public static function fromPostedInput(mixed $input, Currency $currency): ?Money
	{
		if (! is_array($input)) {
			return null;
		}

		$value = $input['value'] ?? '';
		$locale = $input['locale'] ?? null;

		return self::fromInput(
			is_string($value) ? $value : '',
			$currency,
			is_string($locale) ? $locale : Craft::$app->language,
		);
	}

	public static function toDecimal(Money $money): string
	{
		/** @var string $decimal */
		$decimal = MoneyHelper::toDecimal($money);

		return $decimal;
	}

	/**
	 * Format an amount as a localized number for a money input. Zero formats as "0", which a falsy check would lose.
	 */
	public static function toNumber(?Money $money): ?string
	{
		if (! $money instanceof Money) {
			return null;
		}

		/** @var string $number */
		$number = MoneyHelper::toNumber($money);

		return $number;
	}

	public static function toString(Money $money): string
	{
		return (string) MoneyHelper::toString($money);
	}

	public static function zero(Currency $currency): Money
	{
		return new Money(0, $currency);
	}
}
