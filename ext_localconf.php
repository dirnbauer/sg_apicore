<?php

use Psr\Log\LogLevel;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\ResourceRegistry;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Log\Writer\FileWriter;
use TYPO3\CMS\Core\Utility\GeneralUtility;

(static function () {
	// Default Log Configuration
	$GLOBALS['TYPO3_CONF_VARS']['LOG']['SGalinski']['SgApiCore']['Service']['LogService']['writerConfiguration'] = [
		LogLevel::INFO => [
			FileWriter::class => [
				'logFile' => Environment::getVarPath() . '/log/sg_apicore.log'
			]
		]
	];

	$extensionConfiguration = GeneralUtility::makeInstance(ExtensionConfiguration::class);
	if ($extensionConfiguration->isActivateDemoApis()) {
		// DEMO API ENTRIES
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

		// DEMO RESOURCE ENTRIES
		$resourceRegistry = GeneralUtility::makeInstance(ResourceRegistry::class);
		$resourceRegistry->registerResource('public', 'tt_content', '/contents', [
			'allowedOperations' => ['list', 'get'],
			'readFields' => ['uid', 'pid', 'header', 'bodytext', 'CType']
		]);
		$resourceRegistry->registerResource('partner', 'tt_content', '/contents', [
			'allowedOperations' => ['list', 'get', 'create', 'update', 'delete'],
			'writeFields' => ['header', 'bodytext', 'pid'],
			'requiredScopes' => [
				'list' => ['partner:read'],
				'get' => ['partner:read'],
				'create' => ['partner:write'],
				'update' => ['partner:write'],
				'delete' => ['partner:write'],
			]
		]);

		$resourceRegistry->registerResource('public', 'pages', '/pages', [
			'allowedOperations' => ['list', 'get'],
			'readFields' => ['uid', 'pid', 'title', 'doktype', 'slug']
		]);
	}

	if ($extensionConfiguration->isActivateLegacySupport()) {
		$apiRegistry = GeneralUtility::makeInstance(ApiRegistry::class);
		$apiRegistry->registerApi('legacy', ['1'], [
			'authMode' => 'user',
			'authProviders' => ['beareropaquetokenprovider', 'jwtaccesstokenprovider', 'legacytokenprovider']
		]);
	}

	// Register Authentication Service
	\TYPO3\CMS\Core\Utility\ExtensionManagementUtility::addService(
		'sg_apicore',
		'auth',
		\SGalinski\SgApiCore\Security\ApiTokenAuthenticationService::class,
		[
			'title' => 'API Token Authentication',
			'description' => 'Authenticates users based on API tokens (JWT, Opaque, Legacy)',
			'subtype' => 'getUserFE,authUserFE',
			'available' => TRUE,
			'priority' => 80,
			'quality' => 80,
			'os' => '',
			'exec' => '',
			'className' => \SGalinski\SgApiCore\Security\ApiTokenAuthenticationService::class,
		]
	);
})();
