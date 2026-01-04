<?php

//use SGalinski\SgRest\Middleware\RestAuthenticator;
//use SGalinski\SgRest\Middleware\RestDispatcher;
//
//return [
//	'frontend' => [
//		// register the API core dispatcher middleware
//		'sgalinski/sg-apicore/dispatcher' => [
//			'target' => RestDispatcher::class,
//			'description' => 'Dispatches a REST request',
//			'after' => [
//				'typo3/cms-frontend/site'
//			],
//			'before' => [
//				'typo3/cms-core/request-token-middleware',
//			]
//		],
//		// register the API core authenticator middleware
//		'sgalinski/sg-apicore/authenticator' => [
//			'target' => RestAuthenticator::class,
//			'description' => 'Authenticates a REST request',
//			'before' => [
//				'sgalinski/sg-rest/dispatcher'
//			]
//		]
//	]
//];
