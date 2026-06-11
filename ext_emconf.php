<?php

$EM_CONF['sg_apicore'] = [
	'title' => 'Modern API Core for TYPO3',
	'description' => 'Modern, performance-driven TYPO3 API framework with attribute-based routing and endpoint metadata, multi-API/multi-version setup, tenant-aware request context, OpenAPI 3 generation with Swagger UI and CLI export, MCP/Model Context Protocol tool exposure over JSON-RPC and Streamable HTTP, token/user/backend authentication (opaque bearer + JWT + FE/BE contexts), scope enforcement, auto-CRUD resource registration via TCA/DataHandler, built-in response caching, rate limiting, structured request/response logging with redaction, backend dashboards for APIs/tokens/endpoints/rate-limits/logs, and optional legacy sg_rest compatibility.',
	'category' => 'misc',
	'author' => 'Stefan Galinski',
	'author_email' => 'support@sgalinski.de',
	'author_company' => 'sgalinski Internet Services (https://www.sgalinski.de)',
	'state' => 'stable',
	'version' => '1.22.0',
	'constraints' => [
		'depends' => [
			'typo3' => '12.4.0-13.4.99',
			'php' => '8.3.0-8.4.99',
		],
		'conflicts' => [],
		'suggests' => [],
	],
];
