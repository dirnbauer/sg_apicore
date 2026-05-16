<?php

$EM_CONF['sg_apicore'] = [
	'title' => 'Modern API Core for TYPO3',
	'description' => 'Modern, performance-driven TYPO3 API framework with attribute-based routing and endpoint metadata, multi-API/multi-version setup, tenant-aware request context, OpenAPI 3 generation with Swagger UI and CLI export, token/user/backend authentication (opaque bearer + JWT + FE/BE contexts), scope enforcement, auto-CRUD resource registration via TCA/DataHandler, built-in response caching, rate limiting, structured request/response logging with redaction, backend dashboards for APIs/tokens/endpoints/rate-limits/logs, and optional legacy sg_rest compatibility.',
	'category' => 'misc',
	'author' => 'Stefan Galinski',
	'author_email' => 'support@sgalinski.de',
	'author_company' => 'sgalinski Internet Services (https://www.sgalinski.de)',
	'state' => 'stable',
	'version' => '14.0.0',
	'constraints' => [
		'depends' => [
			'typo3' => '14.3.0-14.9.99',
			'php' => '8.2.0-8.5.99',
		],
		'conflicts' => [],
		'suggests' => [],
	],
];
