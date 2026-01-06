<?php

use SGalinski\SgApiCore\Controller\Backend\ApiCoreController;

return [
	'system_SgApiCore' => [
		'parent' => 'system',
		'position' => ['after' => 'belog'],
		'access' => 'admin',
		'workspaces' => 'live',
		'path' => '/module/system/sgapicore',
		'labels' => 'LLL:EXT:sg_apicore/Resources/Private/Language/locallang_backend.xlf',
		'extensionName' => 'SgApiCore',
		'controllerActions' => [
			ApiCoreController::class => [
				'index',
				'tokens',
				'createToken',
				'revokeToken',
				'regenerateToken',
				'providers',
				'endpoints',
			],
		],
	],
];
