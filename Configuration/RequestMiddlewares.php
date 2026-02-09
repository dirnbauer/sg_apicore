<?php

/***************************************************************
 *  Copyright notice
 *  (c) sgalinski Internet Services (https://www.sgalinski.de)
 *
 *  All rights reserved
 *
 *  This file is part of the TYPO3 CMS project.
 *  It is free software; you can redistribute it and/or modify it under
 *  the terms of the "GNU General Public License", either version 3
 *  of the License or any later version.
 ***************************************************************/

use SGalinski\SgApiCore\Middleware\ApiCacheMiddleware;
use SGalinski\SgApiCore\Middleware\ApiRequestMiddleware;
use SGalinski\SgApiCore\Middleware\ApiTypoScriptMiddleware;
use SGalinski\SgApiCore\Middleware\LegacyRoutingMiddleware;
use SGalinski\SgApiCore\Middleware\RateLimitMiddleware;

return [
	'frontend' => [
		'sgalinski/sg-apicore/legacy-routing' => [
			'target' => LegacyRoutingMiddleware::class,
			'description' => 'Maps legacy sg_rest URLs to the new API structure',
			'after' => [
				'typo3/cms-frontend/site'
			],
			'before' => [
				'typo3/cms-frontend/frontend-user-authenticator'
			]
		],
		'sgalinski/sg-apicore/api-setup' => [
			'target' => \SGalinski\SgApiCore\Middleware\ApiSetupMiddleware::class,
			'description' => 'Initializes the API request context',
			'after' => [
				'typo3/cms-frontend/site',
				'sgalinski/sg-apicore/legacy-routing'
			],
			'before' => [
				'typo3/cms-frontend/frontend-user-authenticator'
			]
		],
		'sgalinski/sg-apicore/api-auth' => [
			'target' => \SGalinski\SgApiCore\Middleware\ApiAuthMiddleware::class,
			'description' => 'Handles API authentication and scope validation',
			'after' => [
				'typo3/cms-frontend/frontend-user-authenticator',
				'sgalinski/sg-apicore/api-setup'
			],
			'before' => [
				'sgalinski/sg-apicore/api-typoscript'
			]
		],
		'sgalinski/sg-apicore/api-typoscript' => [
			'target' => ApiTypoScriptMiddleware::class,
			'description' => 'Initializes full TypoScript context when required',
			'after' => [
				'sgalinski/sg-apicore/api-auth'
			],
			'before' => [
				'sgalinski/sg-apicore/rate-limit'
			]
		],
		'sgalinski/sg-apicore/rate-limit' => [
			'target' => RateLimitMiddleware::class,
			'description' => 'Enforces API rate limits',
			'after' => [
				'sgalinski/sg-apicore/api-auth'
			],
			'before' => [
				'sgalinski/sg-apicore/api-cache'
			]
		],
		'sgalinski/sg-apicore/api-cache' => [
			'target' => ApiCacheMiddleware::class,
			'description' => 'Handles API response caching',
			'after' => [
				'sgalinski/sg-apicore/rate-limit'
			],
			'before' => [
				'sgalinski/sg-apicore/api-request'
			]
		],
		'sgalinski/sg-apicore/api-request' => [
			'target' => ApiRequestMiddleware::class,
			'description' => 'Dispatches an API request',
			'after' => [
				'sgalinski/sg-apicore/api-cache'
			],
			'before' => [
				'typo3/cms-frontend/base-redirect-resolver',
			]
		],
	]
];
