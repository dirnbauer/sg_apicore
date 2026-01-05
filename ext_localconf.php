<?php

use SGalinski\SgApiCore\Service\ApiRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

(static function () {
	$apiRegistry = GeneralUtility::makeInstance(ApiRegistry::class);
	$apiRegistry->registerApi('public', ['1']);
	$apiRegistry->registerApi('partner', ['1']);
})();
