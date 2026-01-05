<?php

use SGalinski\SgApiCore\Middleware\ApiRequestMiddleware;

return [
	'frontend' => [
		'sgalinski/sg-apicore/api-request' => [
			'target' => ApiRequestMiddleware::class,
			'description' => 'Dispatches an API request',
			'after' => [
				'typo3/cms-frontend/site'
			],
			'before' => [
				'typo3/cms-frontend/base-redirect-resolver',
			]
		],
	]
];
