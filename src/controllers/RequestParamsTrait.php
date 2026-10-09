<?php

declare(strict_types=1);

namespace fostercommerce\netterms\controllers;

use Craft;
use fostercommerce\netterms\models\Account;
use fostercommerce\netterms\models\Buyer;
use fostercommerce\netterms\Plugin;
use yii\web\BadRequestHttpException;
use yii\web\NotFoundHttpException;

/**
 * Reads typed body params, since `getBodyParam()` returns mixed.
 */
trait RequestParamsTrait
{
	/**
	 * @throws BadRequestHttpException if the param is missing or isn’t an ID
	 */
	private function requireIdParam(string $name): int
	{
		$value = $this->request->getRequiredBodyParam($name);

		if (! is_numeric($value)) {
			throw new BadRequestHttpException(Craft::t(Plugin::HANDLE, 'error.notAnId', [
				'name' => $name,
			]));
		}

		return (int) $value;
	}

	/**
	 * @throws BadRequestHttpException if the query param is missing or isn’t an ID
	 */
	private function requireQueryIdParam(string $name): int
	{
		$value = $this->request->getRequiredQueryParam($name);

		if (! is_numeric($value)) {
			throw new BadRequestHttpException(Craft::t(Plugin::HANDLE, 'error.notAnId', [
				'name' => $name,
			]));
		}

		return (int) $value;
	}

	/**
	 * Read an ID from a plain input or the first ID an element select posts.
	 */
	private function getIdParam(string $name): ?int
	{
		$value = $this->request->getBodyParam($name);

		if (is_array($value)) {
			$value = reset($value);
		}

		return is_numeric($value) ? (int) $value : null;
	}

	/**
	 * @throws BadRequestHttpException if the `accountId` param is missing or isn’t an ID
	 * @throws NotFoundHttpException if no account has the posted `accountId`
	 */
	private function requireAccountParam(): Account
	{
		$account = Plugin::getInstance()->getAccounts()->getAccountById($this->requireIdParam('accountId'));

		if (! $account instanceof Account) {
			throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'error.accountNotFound'));
		}

		return $account;
	}

	/**
	 * @throws NotFoundHttpException if the account has no buyer with the ID
	 */
	private function requireBuyerOnAccount(Account $account, int $buyerId): Buyer
	{
		$buyer = Plugin::getInstance()->getAccounts()->getBuyerById($buyerId);

		if (! $buyer instanceof Buyer || $buyer->accountId !== $account->id) {
			throw new NotFoundHttpException(Craft::t(Plugin::HANDLE, 'error.buyerNotFound'));
		}

		return $buyer;
	}

	private function getFormattingLocaleId(): string
	{
		return Craft::$app->getFormattingLocale()->id ?? Craft::$app->language;
	}

	private function getTextParam(string $name): string
	{
		$value = $this->request->getBodyParam($name);

		return is_string($value) ? trim($value) : '';
	}
}
