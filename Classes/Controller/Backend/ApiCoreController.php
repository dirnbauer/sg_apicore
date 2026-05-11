<?php

/***************************************************************
 *  Copyright notice
 *  (c) sgalinski Internet Services (https://www.sgalinski.de)
 *
 *  All rights reserved
 *
 *  This file is part of the TYPO3 CMS project.
 *  It is free software; you can redistribute it and/or modify it under
 *  the terms of the "GNU General Public License", either version 3
 *  of the License or any later version.
 ***************************************************************/

namespace SGalinski\SgApiCore\Controller\Backend;

use Doctrine\DBAL\Exception;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Random\RandomException;
use ReflectionException;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Domain\Repository\TokenRepository;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\EndpointDiscoveryService;
use SGalinski\SgApiCore\Service\LogDashboardService;
use SGalinski\SgApiCore\Service\RateLimitDashboardService;
use SGalinski\SgApiCore\Service\TokenService;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\UriBuilder as BackendUriBuilder;
use TYPO3\CMS\Backend\Template\Components\ButtonBar;
use TYPO3\CMS\Backend\Template\ModuleTemplate;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Authentication\BackendUserAuthentication;
use TYPO3\CMS\Core\Imaging\Icon;
use TYPO3\CMS\Core\Imaging\IconFactory;
use TYPO3\CMS\Core\Imaging\IconSize;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Extbase\Utility\LocalizationUtility;

/**
 * Backend Controller for the API Core Module
 */
#[AsController]
class ApiCoreController extends ActionController {
	private const string TOKEN_FILTER_STATE_KEY = 'sg_apicore_tokens_filters';
	private const string TOKEN_MODULE_PATH = '/typo3/module/system/sgapicore';

	/**
	 * @param ApiRegistry $apiRegistry
	 * @param TokenRepository $tokenRepository
	 * @param TokenService $tokenService
	 * @param EndpointDiscoveryService $endpointDiscoveryService
	 * @param ModuleTemplateFactory $moduleTemplateFactory
	 * @param IconFactory $iconFactory
	 * @param ExtensionConfiguration $extensionConfiguration
	 * @param RateLimitDashboardService $rateLimitDashboardService
	 * @param LogDashboardService $logDashboardService
	 */
	public function __construct(
		protected readonly ApiRegistry $apiRegistry,
		protected readonly TokenRepository $tokenRepository,
		protected readonly TokenService $tokenService,
		protected readonly EndpointDiscoveryService $endpointDiscoveryService,
		protected readonly ModuleTemplateFactory $moduleTemplateFactory,
		protected readonly BackendUriBuilder $backendUriBuilder,
		protected readonly IconFactory $iconFactory,
		protected readonly ExtensionConfiguration $extensionConfiguration,
		protected readonly RateLimitDashboardService $rateLimitDashboardService,
		protected readonly LogDashboardService $logDashboardService
	) {
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
		$moduleTemplate->assign('apiPathPrefix', $this->extensionConfiguration->getApiPathPrefix());
		$moduleTemplate->assign('currentTab', 'index');
		return $moduleTemplate->renderResponse('Backend/ApiCore/Index');
	}

	/**
	 * Token Management
	 *
	 * @param array $filters
	 * @return ResponseInterface
	 * @throws Exception
	 */
	public function tokensAction(array $filters = []): ResponseInterface {
		$filters = $this->resolveAndPersistTokenFilters($filters);

		if ($filters['tokenCategory'] === 'm2m') {
			$filters['isUserToken'] = 0;
			$filters['isRefreshToken'] = 0;
		} elseif ($filters['tokenCategory'] === 'user') {
			$filters['isUserToken'] = 1;
			$filters['isRefreshToken'] = 0;
		} elseif ($filters['tokenCategory'] === 'refresh') {
			$filters['isRefreshToken'] = 1;
			unset($filters['isUserToken']);
		}

		$tokens = $this->tokenRepository->findAllWithFilters($filters);
		if ($filters['tokenCategory'] === 'user') {
			$hasUserAccessTokens = \count($tokens) > 0;
		} else {
			$userTokenFilters = $filters;
			$userTokenFilters['isUserToken'] = 1;
			$userTokenFilters['isRefreshToken'] = 0;
			$hasUserAccessTokens = \count($this->tokenRepository->findAllWithFilters($userTokenFilters)) > 0;
		}

		$apis = $this->apiRegistry->getApis();
		$apiOptions = array_combine(array_keys($apis), array_keys($apis));

		$moduleTemplate = $this->moduleTemplateFactory->create($this->request);
		$this->prepareDocHeader($moduleTemplate);
		$moduleTemplate->setTitle('API Core - Tokens');
		$moduleTemplate->assign('tokens', $tokens);
		$moduleTemplate->assign('apis', $apiOptions);
		$moduleTemplate->assign('filters', $filters);
		$moduleTemplate->assign('hasUserAccessTokens', $hasUserAccessTokens);
		$moduleTemplate->assign('tokenListReturnUrl', $this->buildTokensModuleUrl($filters));
		$moduleTemplate->assign('currentTab', 'tokens');
		return $moduleTemplate->renderResponse('Backend/ApiCore/Tokens');
	}

	/**
	 * Create a new token
	 *
	 * @param string $apiId
	 * @param string $tenantId
	 * @param string $label
	 * @param string $tokenKey
	 * @param string $scopes
	 * @param int $expiresDays
	 * @return ResponseInterface
	 * @throws JsonException
	 * @throws RandomException
	 */
	public function createTokenAction(
		string $apiId,
		string $tenantId,
		string $label,
		string $tokenKey = '',
		string $scopes = '',
		int $expiresDays = 0,
		int $feUserId = 0,
		string $returnUrl = ''
	): ResponseInterface {
		$newTokenKey = $tokenKey !== '' ? $tokenKey : $this->tokenService->generateRandomToken();
		$expiresAt = $expiresDays > 0 ? time() + ($expiresDays * 24 * 3600) : 0;
		$userId = $feUserId > 0 ? $feUserId : NULL;

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
			$userId, // optional FE user-bound token
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
		$moduleTemplate->assign('returnUrl', $this->resolveTokenReturnUrl($returnUrl));
		$moduleTemplate->assign('currentTab', 'tokens');
		return $moduleTemplate->renderResponse('Backend/ApiCore/TokenCreated');
	}

	/**
	 * Revoke a token
	 *
	 * @param int $uid
	 * @return ResponseInterface
	 */
	public function revokeTokenAction(int $uid, string $returnUrl = ''): ResponseInterface {
		$this->tokenRepository->revoke($uid);
		$this->addFlashMessage('Token revoked successfully.');
		return $this->redirectToUri($this->resolveTokenReturnUrl($returnUrl));
	}

	/**
	 * Regenerate a token key
	 *
	 * @param int $uid
	 * @param string $returnUrl
	 * @return ResponseInterface
	 * @throws RandomException
	 */
	public function regenerateTokenAction(int $uid, string $returnUrl = ''): ResponseInterface {
		$newTokenKey = $this->tokenService->generateRandomToken();
		$this->tokenRepository->updateTokenHash($uid, hash('sha256', $newTokenKey));

		$this->addFlashMessage('Token regenerated successfully. Please copy the new key now.');

		$moduleTemplate = $this->moduleTemplateFactory->create($this->request);
		$this->prepareDocHeader($moduleTemplate);
		$moduleTemplate->setTitle('API Core - Token Regenerated');
		$moduleTemplate->assign('tokenKey', $newTokenKey);
		$moduleTemplate->assign('isRegenerated', TRUE);
		$moduleTemplate->assign('returnUrl', $this->resolveTokenReturnUrl($returnUrl));
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
	 * @throws ReflectionException
	 */
	public function endpointsAction(): ResponseInterface {
		$endpoints = $this->endpointDiscoveryService->getAllEndpoints();
		$moduleTemplate = $this->moduleTemplateFactory->create($this->request);
		$this->prepareDocHeader($moduleTemplate);
		$moduleTemplate->setTitle('API Core - Endpoints');
		$moduleTemplate->assign('endpoints', $endpoints);
		$moduleTemplate->assign('apiPathPrefix', $this->extensionConfiguration->getApiPathPrefix());
		$moduleTemplate->assign('currentTab', 'endpoints');
		return $moduleTemplate->renderResponse('Backend/ApiCore/Endpoints');
	}

	/**
	 * Rate limit dashboard
	 *
	 * @param array<string, mixed> $filters
	 * @return ResponseInterface
	 */
	public function rateLimitsAction(array $filters = []): ResponseInterface {
		$dashboardData = $this->rateLimitDashboardService->getDashboardData($filters);
		$moduleTemplate = $this->moduleTemplateFactory->create($this->request);
		$this->prepareDocHeader($moduleTemplate);
		$moduleTemplate->setTitle('API Core - Rate Limits');
		$moduleTemplate->assignMultiple($dashboardData);
		$moduleTemplate->assign('currentTab', 'rateLimits');
		return $moduleTemplate->renderResponse('Backend/ApiCore/RateLimits');
	}

	/**
	 * Log dashboard
	 *
	 * @param array $filters
	 * @return ResponseInterface
	 */
	public function logsAction(array $filters = []): ResponseInterface {
		$hours = isset($filters['hours']) ? (int) $filters['hours'] : 24;
		$maxLines = isset($filters['maxLines']) ? (int) $filters['maxLines'] : 5000;
		$includeErrors = !isset($filters['includeErrors']) || (bool) $filters['includeErrors'];

		$hours = max(1, min($hours, 168));
		$maxLines = max(500, min($maxLines, 50000));

		$filters = [
			'hours' => $hours,
			'maxLines' => $maxLines,
			'includeErrors' => $includeErrors ? 1 : 0,
		];

		$dashboardData = $this->logDashboardService->getDashboardData($hours, $maxLines, $includeErrors);
		$moduleTemplate = $this->moduleTemplateFactory->create($this->request);
		$this->prepareDocHeader($moduleTemplate);
		$moduleTemplate->setTitle('API Core - Logs');
		$moduleTemplate->assignMultiple($dashboardData);
		$moduleTemplate->assign('filters', $filters);
		$moduleTemplate->assign('currentTab', 'logs');
		return $moduleTemplate->renderResponse('Backend/ApiCore/Logs');
	}

	/**
	 * Prepares the DocHeader with meta-information
	 *
	 * @param ModuleTemplate $moduleTemplate
	 */
	protected function prepareDocHeader(ModuleTemplate $moduleTemplate): void {
		$pageInfo = BackendUtility::readPageAccess(0, $GLOBALS['BE_USER']->getPagePermsClause(1));
		if (\is_array($pageInfo)) {
			$moduleTemplate->getDocHeaderComponent()->setMetaInformation($pageInfo);
		}

		// Refresh button using core translation
		$buttonBar = $moduleTemplate->getDocHeaderComponent()->getButtonBar();
		$refreshTitle = LocalizationUtility::translate('labels.reload', 'core') ?? 'Reload';

		$iconSize = Icon::SIZE_SMALL;
		if (class_exists(IconSize::class)) {
			$iconSize = IconSize::SMALL;
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
				'M' => [], // You can specify additional arguments here if required
			]);
		$buttonBar->addButton($shortcutButton, ButtonBar::BUTTON_POSITION_RIGHT);
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return array<string,mixed>
	 */
	private function resolveAndPersistTokenFilters(array $filters): array {
		$beUser = $GLOBALS['BE_USER'] ?? NULL;
		$storedFilters = $beUser instanceof BackendUserAuthentication
			? $beUser->getModuleData(self::TOKEN_FILTER_STATE_KEY, 'ses')
			: NULL;
		if (($filters === []) && \is_array($storedFilters)) {
			$filters = $storedFilters;
		}

		$normalizedFilters = [
			'apiId' => trim((string) ($filters['apiId'] ?? '')),
			'tenantId' => trim((string) ($filters['tenantId'] ?? '')),
			'status' => trim((string) ($filters['status'] ?? '')),
			'tokenCategory' => trim((string) ($filters['tokenCategory'] ?? 'm2m')),
		];

		if (!\in_array($normalizedFilters['tokenCategory'], ['m2m', 'user', 'refresh'], TRUE)) {
			$normalizedFilters['tokenCategory'] = 'm2m';
		}
		if (
			$normalizedFilters['status'] !== ''
			&& !\in_array($normalizedFilters['status'], ['active', 'expired', 'revoked'], TRUE)
		) {
			$normalizedFilters['status'] = '';
		}

		if ($beUser instanceof BackendUserAuthentication) {
			$beUser->pushModuleData(self::TOKEN_FILTER_STATE_KEY, $normalizedFilters);
		}

		return $normalizedFilters;
	}

	/**
	 * @param array<string,mixed> $filters
	 * @return string
	 * @throws RouteNotFoundException
	 */
	private function buildTokensModuleUrl(array $filters = []): string {
		$parameters = ['action' => 'tokens'];
		if ($filters !== []) {
			$parameters['filters'] = $filters;
		}

		return (string) $this->backendUriBuilder->buildUriFromRoute('system_SgApiCore', $parameters);
	}

	private function resolveTokenReturnUrl(string $returnUrl): string {
		$trimmedReturnUrl = trim($returnUrl);
		if ($trimmedReturnUrl === '') {
			return $this->buildTokensModuleUrl($this->resolveAndPersistTokenFilters([]));
		}

		$path = (string) (parse_url($trimmedReturnUrl, PHP_URL_PATH) ?? '');
		if (!str_starts_with($path, self::TOKEN_MODULE_PATH)) {
			return $this->buildTokensModuleUrl($this->resolveAndPersistTokenFilters([]));
		}

		return $trimmedReturnUrl;
	}
}
