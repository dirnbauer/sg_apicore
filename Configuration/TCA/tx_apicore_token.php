<?php

return [
	'ctrl' => [
		'title' => 'LLL:EXT:sg_apicore/Resources/Private/Language/locallang_db.xlf:tx_apicore_token',
		'label' => 'label',
		'tstamp' => 'tstamp',
		'crdate' => 'crdate',
		'delete' => 'deleted',
		'searchFields' => 'label,token,tenant_id,api_id',
		'iconfile' => 'EXT:core/Resources/Public/Icons/T3Icons/content/content-text.svg',
		'hideTable' => TRUE,
		'rootLevel' => 1,
	],
	'columns' => [
		'pid' => [
			'label' => 'pid',
			'config' => [
				'type' => 'passthrough'
			]
		],
		'tenant_id' => [
			'label' => 'LLL:EXT:sg_apicore/Resources/Private/Language/locallang_db.xlf:tx_apicore_token.tenant_id',
			'config' => [
				'type' => 'input',
				'size' => 30,
				'eval' => 'trim,required'
			],
		],
		'api_id' => [
			'label' => 'LLL:EXT:sg_apicore/Resources/Private/Language/locallang_db.xlf:tx_apicore_token.api_id',
			'config' => [
				'type' => 'input',
				'size' => 30,
				'eval' => 'trim,required'
			],
		],
		'token_hash' => [
			'label' => 'LLL:EXT:sg_apicore/Resources/Private/Language/locallang_db.xlf:tx_apicore_token.token_hash',
			'description' => 'LLL:EXT:sg_apicore/Resources/Private/Language/locallang_db.xlf:tx_apicore_token.token_hash.description',
			'config' => [
				'type' => 'input',
				'size' => 64,
				'eval' => 'trim,required',
			],
		],
		'user_id' => [
			'label' => 'LLL:EXT:sg_apicore/Resources/Private/Language/locallang_db.xlf:tx_apicore_token.user_id',
			'config' => [
				'type' => 'input',
				'size' => 10,
				'eval' => 'int',
				'default' => 0
			],
		],
		'is_refresh_token' => [
			'label' => 'LLL:EXT:sg_apicore/Resources/Private/Language/locallang_db.xlf:tx_apicore_token.is_refresh_token',
			'config' => [
				'type' => 'check',
				'default' => 0
			],
		],
		'label' => [
			'label' => 'LLL:EXT:sg_apicore/Resources/Private/Language/locallang_db.xlf:tx_apicore_token.label',
			'config' => [
				'type' => 'input',
				'size' => 30,
				'eval' => 'trim'
			],
		],
		'scopes' => [
			'label' => 'LLL:EXT:sg_apicore/Resources/Private/Language/locallang_db.xlf:tx_apicore_token.scopes',
			'description' => 'LLL:EXT:sg_apicore/Resources/Private/Language/locallang_db.xlf:tx_apicore_token.scopes.description',
			'config' => [
				'type' => 'text',
				'cols' => 40,
				'rows' => 5,
				'eval' => 'trim',
				'default' => '[]'
			],
		],
		'expires_at' => [
			'label' => 'LLL:EXT:sg_apicore/Resources/Private/Language/locallang_db.xlf:tx_apicore_token.expires_at',
			'config' => [
				'type' => 'input',
				'renderType' => 'inputDateTime',
				'eval' => 'datetime',
				'default' => 0
			],
		],
		'revoked_at' => [
			'label' => 'LLL:EXT:sg_apicore/Resources/Private/Language/locallang_db.xlf:tx_apicore_token.revoked_at',
			'config' => [
				'type' => 'input',
				'renderType' => 'inputDateTime',
				'eval' => 'datetime',
				'default' => 0
			],
		],
		'last_used_at' => [
			'label' => 'LLL:EXT:sg_apicore/Resources/Private/Language/locallang_db.xlf:tx_apicore_token.last_used_at',
			'config' => [
				'type' => 'input',
				'renderType' => 'inputDateTime',
				'eval' => 'datetime',
				'default' => 0,
				'readOnly' => TRUE,
			],
		],
	],
	'types' => [
		'0' => ['showitem' => 'label, tenant_id, api_id, token_hash, user_id, is_refresh_token, scopes, expires_at, revoked_at, last_used_at'],
	],
];
