<?php

/***************************************************************
 *  Copyright notice
 *
 *  (c) sgalinski Internet Services (https://www.sgalinski.de)
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
 ***************************************************************/

namespace SGalinski\SgApiCore\Controller\Backend;

use Psr\Http\Message\ResponseInterface;
use Random\RandomException;
use SGalinski\SgApiCore\Domain\Repository\TokenRepository;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\EndpointDiscoveryService;
use SGalinski\SgApiCore\Service\TokenService;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Backend Controller for the API Core Module
 */
#[AsController]
class ApiCoreController extends ActionController {
	/**
	 * @var ApiRegistry
	 */
	protected ApiRegistry $apiRegistry;

	/**
	 * @var TokenRepository
	 */
	protected TokenRepository $tokenRepository;

	/**
	 * @var TokenService
	 */
	protected TokenService $tokenService;

	/**
	 * @var EndpointDiscoveryService
	 */
	protected EndpointDiscoveryService $endpointDiscoveryService;

	/**
	 * @var ModuleTemplateFactory
	 */
	protected ModuleTemplateFactory $moduleTemplateFactory;

	/**
	 * @var IconFactory
	 */
	protected IconFactory $iconFactory;

	/**
	 * @param ApiRegistry $apiRegistry
	 * @param TokenRepository $tokenRepository
	 * @param TokenService $tokenService
	 * @param EndpointDiscoveryService $endpointDiscoveryService
	 * @param ModuleTemplateFactory $moduleTemplateFactory
	 * @param IconFactory $iconFactory
	 */
	public function __construct(
		ApiRegistry $apiRegistry,
		TokenRepository $tokenRepository,
		TokenService $tokenService,
		EndpointDiscoveryService $endpointDiscoveryService,
		ModuleTemplateFactory $moduleTemplateFactory,
		IconFactory $iconFactory
	) {
		$this->apiRegistry = $apiRegistry;
		$this->tokenRepository = $tokenRepository;
		$this->tokenService = $tokenService;
		$this->endpointDiscoveryService = $endpointDiscoveryService;
		$this->moduleTemplateFactory = $moduleTemplateFactory;
		$this->iconFactory = $iconFactory;
	}

	/**
	 * API Overview
	 *
	 * @return ResponseInterface
	 */
	public function indexAction(): ResponseInterface {
		$apis = $this->apiRegistry->getApis();
		$moduleTemplate = $this->moduleTemplateFactory->create($this->request);
		$this->prepareDocHeader($moduleTemplate);
		$moduleTemplate->setTitle('API Core - Overview');
		$moduleTemplate->assign('apis', $apis);
		$moduleTemplate->assign('currentTab', 'index');
		return $moduleTemplate->renderResponse('Backend/ApiCore/Index');
	}

	/**
	 * Token Management
	 *
	 * @param array $filters
	 * @return ResponseInterface
	 * @throws \Doctrine\DBAL\Exception
	 */
	public function tokensAction(array $filters = []): ResponseInterface {
		if (!isset($filters['isUserToken'])) {
			$filters['isUserToken'] = 0;
		}

		$tokens = $this->tokenRepository->findAllWithFilters($filters);
		$apis = $this->apiRegistry->getApis();
		$apiOptions = array_combine(array_keys($apis), array_keys($apis));

		$moduleTemplate = $this->moduleTemplateFactory->create($this->request);
		$this->prepareDocHeader($moduleTemplate);
		$moduleTemplate->setTitle('API Core - Tokens');
		$moduleTemplate->assign('tokens', $tokens);
		$moduleTemplate->assign('apis', $apiOptions);
		$moduleTemplate->assign('filters', $filters);
		$moduleTemplate->assign('currentTab', 'tokens');
		return $moduleTemplate->renderResponse('Backend/ApiCore/Tokens');
	}

	/**
	 * Create a new token
	 *
	 * @param string $apiId
	 * @param string $tenantId
	 * @param string $label
	 * @param string $scopes
	 * @param int $expiresDays
	 * @return ResponseInterface
	 * @throws \JsonException
	 * @throws RandomException
	 */
	public function createTokenAction(
		string $apiId,
		string $tenantId,
		string $label,
		string $scopes = '',
		int $expiresDays = 0
	): ResponseInterface {
		$newTokenKey = $this->tokenService->generateRandomToken();
		$expiresAt = $expiresDays > 0 ? time() + ($expiresDays * 24 * 3600) : 0;

		$scopeArray = [];
		if ($scopes !== '') {
			$scopeArray = array_map('trim', explode(',', $scopes));
		}

		$this->tokenService->createToken(
			$newTokenKey,
			$apiId,
			$tenantId,
			0, // root level for manual tokens
			$scopeArray,
			NULL, // no user for manual M2M tokens
			FALSE, // not a refresh token
			$expiresAt,
			$label
		);

		$this->addFlashMessage('Token created successfully. Please copy it now, it will not be shown again.');

		$moduleTemplate = $this->moduleTemplateFactory->create($this->request);
		$this->prepareDocHeader($moduleTemplate);
		$moduleTemplate->setTitle('API Core - Token Created');
		$moduleTemplate->assign('tokenKey', $newTokenKey);
		$moduleTemplate->assign('apiId', $apiId);
		$moduleTemplate->assign('tenantId', $tenantId);
		$moduleTemplate->assign('currentTab', 'tokens');
		return $moduleTemplate->renderResponse('Backend/ApiCore/TokenCreated');
	}

	/**
	 * Revoke a token
	 *
	 * @param int $uid
	 * @return ResponseInterface
	 */
	public function revokeTokenAction(int $uid): ResponseInterface {
		$this->tokenRepository->revoke($uid);
		$this->addFlashMessage('Token revoked successfully.');
		return $this->redirect('tokens');
	}

	/**
	 * Regenerate a token key
	 *
	 * @param int $uid
	 * @return ResponseInterface
	 * @throws RandomException
	 */
	public function regenerateTokenAction(int $uid): ResponseInterface {
		$newTokenKey = $this->tokenService->generateRandomToken();
		$this->tokenRepository->updateTokenHash($uid, hash('sha256', $newTokenKey));

		$this->addFlashMessage('Token regenerated successfully. Please copy the new key now.');

		$moduleTemplate = $this->moduleTemplateFactory->create($this->request);
		$this->prepareDocHeader($moduleTemplate);
		$moduleTemplate->setTitle('API Core - Token Regenerated');
		$moduleTemplate->assign('tokenKey', $newTokenKey);
		$moduleTemplate->assign('isRegenerated', TRUE);
		$moduleTemplate->assign('currentTab', 'tokens');
		return $moduleTemplate->renderResponse('Backend/ApiCore/TokenCreated');
	}

	/**
	 * Provider Configuration
	 *
	 * @return ResponseInterface
	 */
	public function providersAction(): ResponseInterface {
		$apis = $this->apiRegistry->getApis();
		$moduleTemplate = $this->moduleTemplateFactory->create($this->request);
		$this->prepareDocHeader($moduleTemplate);
		$moduleTemplate->setTitle('API Core - Providers');
		$moduleTemplate->assign('apis', $apis);
		$moduleTemplate->assign('currentTab', 'providers');
		return $moduleTemplate->renderResponse('Backend/ApiCore/Providers');
	}

	/**
	 * Endpoint Overview
	 *
	 * @return ResponseInterface
	 * @throws \ReflectionException
	 */
	public function endpointsAction(): ResponseInterface {
		$endpoints = $this->endpointDiscoveryService->getAllEndpoints();
		$moduleTemplate = $this->moduleTemplateFactory->create($this->request);
		$this->prepareDocHeader($moduleTemplate);
		$moduleTemplate->setTitle('API Core - Endpoints');
		$moduleTemplate->assign('endpoints', $endpoints);
		$moduleTemplate->assign('currentTab', 'endpoints');
		return $moduleTemplate->renderResponse('Backend/ApiCore/Endpoints');
	}

	/**
	 * Prepares the DocHeader with meta-information
	 *
	 * @param ModuleTemplate $moduleTemplate
	 */
	protected function prepareDocHeader(ModuleTemplate $moduleTemplate): void {
		$pageInfo = BackendUtility::readPageAccess(0, $GLOBALS['BE_USER']->getPagePermsClause(1));
		if (is_array($pageInfo)) {
			$moduleTemplate->getDocHeaderComponent()->setMetaInformation($pageInfo);
		}

		// Refresh button using core translation
		$buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
		$refreshTitle = LocalizationUtility::translate('labels.reload', 'core') ?? 'Reload';

		$iconSize = Icon::SIZE_SMALL;
		if (class_exists(\TYPO3\CMS\Core\Imaging\IconSize::class)) {
			$iconSize = \TYPO3\CMS\Core\Imaging\IconSize::SMALL;
		}

		$refreshButton = $buttonBar->makeLinkButton()
			->setHref(GeneralUtility::getIndpEnv('REQUEST_URI'))
			->setTitle($refreshTitle)
			->setIcon($this->iconFactory->getIcon('actions-refresh', $iconSize));
		$buttonBar->addButton($refreshButton, ButtonBar::BUTTON_POSITION_RIGHT);
		$shortcutButton = $buttonBar->makeShortcutButton()
			->setDisplayName('Shortcut')
			->setRouteIdentifier('system_SgApiCore') // Replace 'web_SgLogs' with your actual module route identifier
			->setArguments([
				'id' => [],
				'M' => [] // You can specify additional arguments here if required
			]);
		$buttonBar->addButton($shortcutButton, ButtonBar::BUTTON_POSITION_RIGHT);
	}
}
