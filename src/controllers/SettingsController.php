<?php

declare(strict_types=1);

namespace fostercommerce\netterms\controllers;

use Craft;
use craft\commerce\Plugin as Commerce;
use craft\helpers\Html;
use craft\web\Controller;
use fostercommerce\netterms\enums\Billing;
use fostercommerce\netterms\Plugin;
use yii\web\Response;

class SettingsController extends Controller
{
	public function actionIndex(): Response
	{
		$this->requireAdmin(false);

		$readOnly = ! Craft::$app->getConfig()->getGeneral()->allowAdminChanges;

		// Disable the inputs where admin changes are off, as Craft's own plugin settings page does
		$settingsHtml = Craft::$app->getView()->namespaceInputs(
			fn (): string => $readOnly ? (string) Html::disableInputs($this->renderSettingsForm(...)) : $this->renderSettingsForm(),
			'settings',
		);

		return $this->renderTemplate('net-terms/settings/_index', [
			'settingsHtml' => $settingsHtml,
			'readOnly' => $readOnly,
		]);
	}

	private function renderSettingsForm(): string
	{
		$plugin = Plugin::getInstance();
		/** @var Commerce $commerce */
		$commerce = Commerce::getInstance();

		return Craft::$app->getView()->renderTemplate('net-terms/_settings', [
			'settings' => $plugin->getSettings(),
			'netTermsGateway' => $plugin->getNetTermsGateway(),
			'isOrderBilling' => $plugin->getBilling() === Billing::Orders,
			'commerceEmails' => $commerce->getEmails()->getAllEnabledEmails(),
		]);
	}
}
