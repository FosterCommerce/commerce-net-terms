<?php

declare(strict_types=1);

namespace fostercommerce\netterms\web\assets\cp;

use craft\web\AssetBundle;
use craft\web\assets\cp\CpAsset;
use craft\web\View;
use fostercommerce\netterms\Plugin;

class NetTermsCpAsset extends AssetBundle
{
	public function init(): void
	{
		$this->sourcePath = __DIR__ . '/dist';

		$this->depends = [
			CpAsset::class,
		];

		$this->css[] = 'css/net-terms-cp.css';
		$this->js[] = 'js/net-terms-cp.js';

		parent::init();
	}

	public function registerAssetFiles($view): void
	{
		parent::registerAssetFiles($view);

		if ($view instanceof View) {
			$view->registerTranslations(Plugin::HANDLE, [
				'buyer.removeConfirm',
				'invoice.voidConfirm',
				'application.reverseConfirm',
				'invoice.issueConfirm',
			]);
		}
	}
}
