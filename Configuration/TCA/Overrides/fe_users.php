<?php

//use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;
//
//call_user_func(static function () {
//	$GLOBALS['TCA']['fe_users']['columns']['usergroup']['exclude'] = TRUE;
//
//	$tabLabel = 'LLL:EXT:sg_apicore/Resources/Private/Language/locallang_db.xlf:fe_users.tab.rest_authentification';
//	$position = '--div--;' . $tabLabel . ', tx_sgrest_auth_token, tx_sgrest_access_groups, tx_sgrest_test_mode';
//
//	ExtensionManagementUtility::addToAllTCAtypes('fe_users', $position);
//	ExtensionManagementUtility::addTCAcolumns(
//		'fe_users',
//		[
//			'tx_sgrest_auth_token' => [
//				'exclude' => TRUE,
//				'label' => 'LLL:EXT:sg_apicore/Resources/Private/Language/locallang_db.xlf:fe_users.tx_sgrest_auth_token',
//				'config' => [
//					'type' => 'input',
//					'size' => 40
//				],
//			],
//			'tx_sgrest_access_groups' => [
//				'exclude' => TRUE,
//				'label' => 'LLL:EXT:sg_apicore/Resources/Private/Language/locallang_db.xlf:fe_users.tx_sgrest_access_groups',
//				'config' => [
//					'type' => 'select',
//					'renderType' => 'selectMultipleSideBySide',
//					'size' => 10,
//					'minitems' => 0,
//					'maxitems' => 99,
//					'itemsProcFunc' => 'SGalinski\\SgRest\\TCA\\TcaProvider->createAccessGroupItemList',
//				],
//			],
//			'tx_sgrest_test_mode' => [
//				'exclude' => TRUE,
//				'label' => 'LLL:EXT:sg_apicore/Resources/Private/Language/locallang_db.xlf:fe_users.tx_sgrest_test_mode',
//				'config' => [
//					'type' => 'check',
//				],
//			],
//		]
//	);
//});
