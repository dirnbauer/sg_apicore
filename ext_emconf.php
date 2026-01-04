<?php

$EM_CONF['sg_apicore'] = [
	'title' => 'API Core',
	'description' => 'Provides an API framework for TYPO3: routing, logging, FE user/bearer auth, entity registration (field whitelist), pagination, custom endpoints and CRUD permissions.',
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
