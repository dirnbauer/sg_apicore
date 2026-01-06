<?php

return [
	'ctrl' => [
		'title' => 'LLL:EXT:sg_apicore/Resources/Private/Language/locallang_db.xlf:tx_apicore_token',
		'label' => 'label',
		'tstamp' => 'tstamp',
		'crdate' => 'crdate',
		'cruser_id' => 'cruser_id',
		'delete' => 'deleted',
		'searchFields' => 'label,token_hash,tenant_id,api_id',
		'iconfile' => 'EXT:core/Resources/Public/Icons/T3Icons/svgs/actions/actions-key.svg',
		'hideTable' => FALSE,
		'rootLevel' => 0,
		'security' => [
			'ignorePageTypeRestriction' => TRUE,
		],
	],
	'palettes' => [
		'management' => [
			'label' => 'LLL:EXT:sg_apicore/Resources/Private/Language/locallang_db.xlf:tx_apicore_token.palette.management',
			'showitem' => 'user_id, is_refresh_token, --break--, expires_at, revoked_at, last_used_at'
		],
	],
	'types' => [
		'0' => ['showitem' => 'label, tenant_id, api_id, token_hash, scopes, --palette--;;management'],
	],
	'columns' => [
		'crdate' => [
			'config' => [
				'type' => 'passthrough',
			],
		],
		'tstamp' => [
			'config' => [
				'type' => 'passthrough',
			],
		],
		'cruser_id' => [
			'config' => [
				'type' => 'passthrough',
			],
		],
		'deleted' => [
			'config' => [
				'type' => 'passthrough',
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
		'tenant_id' => [
			'label' => 'LLL:EXT:sg_apicore/Resources/Private/Language/locallang_db.xlf:tx_apicore_token.tenant_id',
			'description' => 'LLL:EXT:sg_apicore/Resources/Private/Language/locallang_db.xlf:tx_apicore_token.tenant_id.description',
			'config' => [
				'type' => 'input',
				'size' => 30,
				'eval' => 'trim,required'
			],
		],
		'api_id' => [
			'label' => 'LLL:EXT:sg_apicore/Resources/Private/Language/locallang_db.xlf:tx_apicore_token.api_id',
			'description' => 'LLL:EXT:sg_apicore/Resources/Private/Language/locallang_db.xlf:tx_apicore_token.api_id.description',
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
				'renderType' => 'checkboxToggle',
				'default' => 0
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
];
