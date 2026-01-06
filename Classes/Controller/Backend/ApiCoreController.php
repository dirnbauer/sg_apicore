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
use SGalinski\SgApiCore\Domain\Repository\TokenRepository;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\EndpointDiscoveryService;
use SGalinski\SgApiCore\Service\TokenService;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;

/**
 * Backend Controller for the API Core Module
 */
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
	 * @param ApiRegistry $apiRegistry
	 * @param TokenRepository $tokenRepository
	 * @param TokenService $tokenService
	 * @param EndpointDiscoveryService $endpointDiscoveryService
	 * @param ModuleTemplateFactory $moduleTemplateFactory
	 */
	public function __construct(
		ApiRegistry $apiRegistry,
		TokenRepository $tokenRepository,
		TokenService $tokenService,
		EndpointDiscoveryService $endpointDiscoveryService,
		ModuleTemplateFactory $moduleTemplateFactory
	) {
		$this->apiRegistry = $apiRegistry;
		$this->tokenRepository = $tokenRepository;
		$this->tokenService = $tokenService;
		$this->endpointDiscoveryService = $endpointDiscoveryService;
		$this->moduleTemplateFactory = $moduleTemplateFactory;
	}

	/**
	 * API Overview
	 *
	 * @return ResponseInterface
	 */
	public function indexAction(): ResponseInterface {
		$apis = $this->apiRegistry->getApis();
		$moduleTemplate = $this->moduleTemplateFactory->create($this->request);
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
	 * @throws \Random\RandomException
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
	 * Provider Configuration
	 *
	 * @return ResponseInterface
	 */
	public function providersAction(): ResponseInterface {
		$apis = $this->apiRegistry->getApis();
		$moduleTemplate = $this->moduleTemplateFactory->create($this->request);
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
		$moduleTemplate->setTitle('API Core - Endpoints');
		$moduleTemplate->assign('endpoints', $endpoints);
		$moduleTemplate->assign('currentTab', 'endpoints');
		return $moduleTemplate->renderResponse('Backend/ApiCore/Endpoints');
	}
}
