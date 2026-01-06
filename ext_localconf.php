<?php

use Psr\Log\LogLevel;
use SGalinski\SgApiCore\Service\ApiRegistry;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Log\Writer\FileWriter;
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
		LogLevel::INFO => [
			FileWriter::class => [
				'logFile' => Environment::getVarPath() . '/log/sg_apicore.log'
			]
		]
	];
})();
