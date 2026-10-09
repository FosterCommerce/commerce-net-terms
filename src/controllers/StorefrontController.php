<?php

declare(strict_types=1);

namespace fostercommerce\netterms\controllers;

use Craft;
use craft\elements\User;
use craft\web\Controller;
use fostercommerce\netterms\errors\InvalidAmountException;
use fostercommerce\netterms\errors\LedgerException;
use fostercommerce\netterms\helpers\Amounts;
use fostercommerce\netterms\models\Account;
use fostercommerce\netterms\models\Buyer;
use fostercommerce\netterms\Plugin;
use Money\Currency;
use Money\Money;
use yii\web\ForbiddenHttpException;
use yii\web\Response;

/**
 * Lets the people who manage an account set sublimits on the storefront.
 */
class StorefrontController extends Controller
{
	use RequestParamsTrait;

	public function beforeAction($action): bool
	{
		if (! parent::beforeAction($action)) {
			return false;
		}

		$this->requireSiteRequest();
		$this->requirePostRequest();

		return true;
	}

	public function actionSaveBuyer(): ?Response
	{
		$plugin = Plugin::getInstance();
		$account = $this->requireManagedAccount();
		$buyerId = $this->getIdParam('buyerId');

		if ($buyerId !== null) {
			$buyer = $this->requireBuyerOnAccount($account, $buyerId);
		} else {
			$user = Craft::$app->getUsers()->getUserById($this->requireIdParam('userId'));

			if (! $user instanceof User || ! $plugin->getAccounts()->canBeBuyer($account, $user)) {
				throw new ForbiddenHttpException(Craft::t(Plugin::HANDLE, 'error.notEligibleBuyer'));
			}

			$buyer = new Buyer([
				'accountId' => $account->id,
				'userId' => $user->id,
			]);
		}

		try {
			$buyer->sublimit = $this->parseAmount($this->request->getBodyParam('sublimit'), $account->getCurrency());
			$saved = $plugin->getAccounts()->saveBuyer($buyer);
		} catch (InvalidAmountException|LedgerException $userException) {
			return $this->asFailure($userException->getMessage());
		}

		if (! $saved) {
			$errors = implode(' ', $buyer->getFirstErrors());

			return $this->asModelFailure($buyer, $errors !== '' ? $errors : Craft::t(Plugin::HANDLE, 'buyer.couldNotSave'), 'buyer');
		}

		return $this->asModelSuccess($buyer, Craft::t(Plugin::HANDLE, 'buyer.saved'), 'buyer');
	}

	public function actionRemoveBuyer(): ?Response
	{
		$plugin = Plugin::getInstance();
		$account = $this->requireManagedAccount();
		$buyer = $this->requireBuyerOnAccount($account, $this->requireIdParam('buyerId'));

		$plugin->getAccounts()->removeBuyer($buyer);

		return $this->asSuccess(Craft::t(Plugin::HANDLE, $buyer->active ? 'buyer.removed' : 'buyer.deactivated'));
	}

	private function requireManagedAccount(): Account
	{
		$account = $this->requireAccountParam();
		$currentUser = Craft::$app->getUser()->getIdentity();

		if (! $currentUser instanceof User || ! Plugin::getInstance()->getAccounts()->canManage($account, $currentUser)) {
			throw new ForbiddenHttpException(Craft::t(Plugin::HANDLE, 'error.cantManageAccount'));
		}

		return $account;
	}

	/**
	 * Read an amount from a Craft money input or from a plain number typed in the site’s locale.
	 *
	 * @throws InvalidAmountException if the input isn’t a number
	 */
	private function parseAmount(mixed $input, Currency $currency): ?Money
	{
		if (is_array($input)) {
			return Amounts::fromPostedInput($input, $currency);
		}

		return is_string($input) ? Amounts::fromInput($input, $currency, $this->getFormattingLocaleId()) : null;
	}
}
