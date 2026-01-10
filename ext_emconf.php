<?php

$EM_CONF['sg_apicore'] = [
	'title' => 'Modern API Core for TYPO3',
	'description' => 'Modern API Core for TYPO3 - Routing, Easy Configuration via Attributes, Multi-API Support, Multi-Domain Support, Multi-Tenant Support, Backend Token Management, Login/Refresh for Frontend Users for user-scoped APIs, Proper scope management, Swagger UI viewer, and automatic docs generation incl. CLI commands, Public API Support, Private API Support, User API Support, Bearer Opaque Tokens, JWT Tokens, Demo Controllers, Logging, Complete README, and Tests, Performance-driven',
	'category' => 'misc',
	'author' => 'Stefan Galinski',
	'author_email' => 'support@sgalinski.de',
	'author_company' => 'sgalinski Internet Services (https://www.sgalinski.de)',
	'state' => 'stable',
	'version' => '1.1.0',
	'constraints' => [
		'depends' => [
			'typo3' => '12.4.0-13.4.99',
			'php' => '8.3.0-8.4.99',
		],
		'conflicts' => [],
		'suggests' => [],
	],
];
