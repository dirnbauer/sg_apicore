<?php

$EM_CONF['sg_apicore'] = [
	'title' => 'API Core',
	'description' => 'Provides an API framework for TYPO3: Multi-API, Mulit-Tenants, Attribute based endpoint configuration, Logging, Token JWT Bearer auth, User auth, Entity CRUD registration, Custom Endpoints',
	'category' => 'misc',
	'author' => 'Stefan Galinski',
	'author_email' => 'support@sgalinski.de',
	'author_company' => 'sgalinski Internet Services (https://www.sgalinski.de)',
	'state' => 'stable',
	'version' => '1.0.0',
	'constraints' => [
		'depends' => [
			'typo3' => '12.4.0-13.4.99',
			'php' => '8.3.0-8.4.99',
		],
		'conflicts' => [],
		'suggests' => [],
	],
];
