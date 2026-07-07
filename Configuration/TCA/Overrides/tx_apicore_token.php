<?php

declare(strict_types=1);

use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

defined('TYPO3') or die();

// fork-only: backend-user binding for tokens, consumed by BackendBearerOpaqueTokenProvider
/** @phpstan-ignore offsetAccess.nonOffsetAccessible, offsetAccess.nonOffsetAccessible, offsetAccess.nonOffsetAccessible */
$GLOBALS['TCA']['tx_apicore_token']['columns']['be_user_uid'] = [
	'label' => 'LLL:EXT:sg_apicore/Resources/Private/Language/locallang_db.xlf:tx_apicore_token.be_user_uid',
	'config' => [
		'type' => 'group',
		'allowed' => 'be_users',
		'size' => 1,
		'maxitems' => 1,
		'suggestOptions' => [
			'default' => [
				'additionalSearchFields' => 'username,realName,email',
				'addWhere' => ' AND be_users.deleted=0 AND be_users.disable=0',
			],
		],
		'default' => 0,
	],
];

ExtensionManagementUtility::addToAllTCAtypes('tx_apicore_token', 'be_user_uid', '', 'after:user_id');
