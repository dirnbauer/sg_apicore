<?php

use SGalinski\SgApiCore\Service\ApiRegistry;
use TYPO3\CMS\Core\Utility\GeneralUtility;

(static function () {
	$apiRegistry = GeneralUtility::makeInstance(ApiRegistry::class);
	$apiRegistry->registerApi('public', ['1'], [
		'authMode' => 'public'
	]);
	$apiRegistry->registerApi('partner', ['1'], [
		'authMode' => 'token',
		'authProviders' => ['beareropaquetokenprovider']
	]);
	$apiRegistry->registerApi('user', ['1'], [
		'authMode' => 'user',
		'authProviders' => ['beareropaquetokenprovider', 'jwtaccesstokenprovider']
	]);

	// Default Log Configuration
	$GLOBALS['TYPO3_CONF_VARS']['LOG']['SGalinski']['SgApiCore']['Service']['LogService']['writerConfiguration'] = [
		\TYPO3\CMS\Core\Log\LogLevel::INFO => [
			\TYPO3\CMS\Core\Log\Writer\FileWriter::class => [
				'logFile' => 'var/log/sg_apicore.log'
			]
		]
	];
})();
