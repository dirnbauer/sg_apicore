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

namespace SGalinski\SgApiCore\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Random\RandomException;
use SGalinski\SgApiCore\Attribute\ApiLegacyMode;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Service\LogService;
use SGalinski\SgApiCore\Service\PathAnalysisService;
use SGalinski\SgApiCore\Service\ResponseService;
use SGalinski\SgApiCore\Service\Tenant\TenantResolverInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspectFactory;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\TypoScript\AST\Node\RootNode;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScript;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

/**
 * Middleware to setup API request context (Tenant, TSFE, etc.)
 */
class ApiSetupMiddleware implements MiddlewareInterface {
	protected ExtensionConfiguration $extensionConfiguration;
	protected TenantResolverInterface $tenantResolver;
	protected PathAnalysisService $pathAnalysisService;
	protected LogService $logService;
	protected ResponseService $responseService;
	protected Context $context;

	public function __construct(
		ExtensionConfiguration $extensionConfiguration,
		TenantResolverInterface $tenantResolver,
		PathAnalysisService $pathAnalysisService,
		LogService $logService,
		ResponseService $responseService,
		Context $context
	) {
		$this->extensionConfiguration = $extensionConfiguration;
		$this->tenantResolver = $tenantResolver;
		$this->pathAnalysisService = $pathAnalysisService;
		$this->logService = $logService;
		$this->responseService = $responseService;
		$this->context = $context;
	}

	/**
	 * @param ServerRequestInterface $request
	 * @param RequestHandlerInterface $handler
	 * @return ResponseInterface
	 * @throws RandomException
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
		$uri = $request->getUri();
		$path = $uri->getPath();
		$apiPathPrefix = $this->extensionConfiguration->getApiPathPrefix();

		// Respect TYPO3 Language Prefix
		/** @var SiteLanguage $language */
		$language = $request->getAttribute('language');
		$languagePrefix = $language?->getBase()->getPath();
		$pathWithoutLanguage = $path;
		if ($languagePrefix !== NULL && $languagePrefix !== '/' && $languagePrefix !== '') {
			$languagePrefix = '/' . trim($languagePrefix, '/') . '/';
			if (str_starts_with($path, $languagePrefix)) {
				$pathWithoutLanguage = '/' . ltrim(substr($path, strlen($languagePrefix)), '/');
			}
		}

		// Skip if it doesn't start with the API path prefix
		if (!str_starts_with($pathWithoutLanguage, $apiPathPrefix)) {
			return $handler->handle($request);
		}

		// Skip if it contains legacy auth headers and is not already a legacy-mapped request
		// This allows the LegacyRoutingMiddleware to handle these requests if they use the same path prefix
		$hasLegacyAuthHeader = $request->hasHeader('authtoken') || $request->hasHeader('bearertoken');
		if ($hasLegacyAuthHeader && !$request->getAttribute('api.isLegacy')) {
			return $handler->handle($request);
		}

		// Add Request ID
		$requestId = bin2hex(random_bytes(8));
		$request = $request->withAttribute('api.requestId', $requestId);

		// Resolve tenant early
		$tenantResult = $this->tenantResolver->resolve($request);
		if (!$tenantResult->isSuccess()) {
			return $this->responseService->createErrorResponse(
				'Tenant Resolution Failed',
				'Could not resolve a valid tenant for this request. Reason: ' . $tenantResult->getError(),
				$this->extensionConfiguration->getOnMissingTenantStatusCode()
			);
		}
		$request = $request->withAttribute('api.tenant', $tenantResult->getContext());

		// Initialize Language Aspect in Context
		if ($language) {
			$languageAspect = LanguageAspectFactory::createFromSiteLanguage($language);
			$this->context->setAspect('language', $languageAspect);

			// Ensure the language aspect is also reflected in the site attribute for some core processes
			$request = $request->withAttribute('language', $language);
		}

		// Analyze Path if not already done by previous middleware (e.g. LegacyRoutingMiddleware)
		if (!$request->getAttribute('api.id') || !$request->getAttribute('api.version')) {
			$analysis = $this->pathAnalysisService->analyze(
				$path,
				$languagePrefix ? $languagePrefix . ltrim($apiPathPrefix, '/') : NULL
			);
			if ($analysis) {
				$request = $request->withAttribute('api.id', $analysis['apiId']);
				$request = $request->withAttribute('api.version', $analysis['version']);
				$request = $request->withAttribute('api.remainingPath', $analysis['remainingPath']);
			}
		}

		// Mock PageInformation for extensions like gridelements that expect it in TYPO3 13 frontend context
		$siteRootPageId = $tenantResult->getContext()?->getSiteRootPageId() ?? 0;
		$typo3Version = GeneralUtility::makeInstance(Typo3Version::class);
		if ($siteRootPageId > 0 && $typo3Version->getMajorVersion() >= 13
			&& !$request->getAttribute('frontend.page.information')
		) {
			$pageInformation = new \TYPO3\CMS\Frontend\Page\PageInformation();
			$pageInformation->setId($siteRootPageId);

			$rootline = [];
			try {
				$rootlineUtility = GeneralUtility::makeInstance(RootlineUtility::class, $siteRootPageId);
				$rootline = $rootlineUtility->get();
			} catch (\Throwable) {
				// Fallback if the rootline cannot be generated
			}
			$pageInformation->setRootLine($rootline);

			$request = $request->withAttribute('frontend.page.information', $pageInformation);

			// Mock TSFE for Extbase ConfigurationManager in TYPO3 13
			if (!isset($GLOBALS['TSFE'])) {
				$site = $request->getAttribute('site');
				$language = $request->getAttribute('language');
				if ($site instanceof Site && $language instanceof SiteLanguage) {
					$tsfe = GeneralUtility::makeInstance(
						TypoScriptFrontendController::class,
						$this->context,
						$site,
						$language,
						$pageInformation,
						$request->getAttribute('frontend.user')
					);

					$setupArray = [
						'config.' => [
							'tx_sgapicore.' => [
								'persistence.' => [
									'storagePid' => $siteRootPageId
								]
							]
						]
					];

					$frontendTypoScript = new FrontendTypoScript(new RootNode(), [], [], []);
					$frontendTypoScript->setSetupArray($setupArray);
					$request = $request->withAttribute('frontend.typoscript', $frontendTypoScript);
					$tsfe->page = [
						'uid' => $siteRootPageId,
						'starttime' => 0,
						'endtime' => 0,
						'fe_group' => '',
						'tx_staticfilecache_cache_priority' => 0
					];
					$tsfe->id = $siteRootPageId;
					$tsfe->rootLine = $rootline;
					$tsfe->sys_page = GeneralUtility::makeInstance(
						\TYPO3\CMS\Core\Domain\Repository\PageRepository::class
					);
					if (class_exists(\TYPO3\CMS\Core\TypoScript\TemplateService::class)) {
						/** @phpstan-ignore-next-line */
						$tsfe->tmpl = GeneralUtility::makeInstance(\TYPO3\CMS\Core\TypoScript\TemplateService::class);
					}
					$GLOBALS['TSFE'] = $tsfe;
				}
			}

			$GLOBALS['TYPO3_REQUEST'] = $request;
		} elseif ($siteRootPageId > 0 && !isset($GLOBALS['TSFE']) && $typo3Version->getMajorVersion() < 13) {
			// Mock TSFE for TYPO3 12
			$site = $request->getAttribute('site');
			$language = $request->getAttribute('language');
			if ($site && $language) {
				$frontendUser = $request->getAttribute('frontend.user');
				if (!($frontendUser instanceof FrontendUserAuthentication)) {
					$frontendUser = GeneralUtility::makeInstance(FrontendUserAuthentication::class);
				}

				$pageArguments = $request->getAttribute('routing');
				if (!($pageArguments instanceof \TYPO3\CMS\Core\Routing\PageArguments)) {
					$pageArguments = new \TYPO3\CMS\Core\Routing\PageArguments($siteRootPageId, '0', []);
				}

				$tsfe = GeneralUtility::makeInstance(
					TypoScriptFrontendController::class,
					$this->context,
					$site,
					$language,
					$pageArguments,
					$frontendUser
				);
				$tsfe->sys_page = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Domain\Repository\PageRepository::class);

				$rootline = [];
				try {
					$rootlineUtility = GeneralUtility::makeInstance(RootlineUtility::class, $siteRootPageId);
					$rootline = $rootlineUtility->get();
				} catch (\Throwable) {
					// Fallback if the rootline cannot be generated
				}
				$tsfe->rootLine = $rootline;

				if (class_exists(\TYPO3\CMS\Core\TypoScript\TemplateService::class)) {
					/** @phpstan-ignore-next-line */
					$tsfe->tmpl = GeneralUtility::makeInstance(\TYPO3\CMS\Core\TypoScript\TemplateService::class);
				}

				$tsfe->page = [
					'uid' => $siteRootPageId,
					'starttime' => 0,
					'endtime' => 0,
					'fe_group' => '',
					'tx_staticfilecache_cache_priority' => 0
				];
				$tsfe->id = $siteRootPageId;
				$GLOBALS['TSFE'] = $tsfe;
				$GLOBALS['TYPO3_REQUEST'] = $request;
			}
		}

		// Parse JSON Body if applicable (allowance of text/plain is a legacy fallback)
		$contentType = $request->getHeaderLine('Content-Type');
		if (str_contains($contentType, 'application/json') || str_contains($contentType, 'text/plain')) {
			$body = (string) $request->getBody();
			if ($body !== '') {
				try {
					$parsedBody = json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);
					if (is_array($parsedBody)) {
						$request = $request->withParsedBody($parsedBody);
					}
				} catch (\JsonException) {
					// Only throw an error for application/json if it's invalid
					if (str_contains($contentType, 'application/json')) {
						return $this->responseService->createErrorResponse(
							'Invalid JSON',
							'The request body could not be parsed as JSON.',
							400
						);
					}
				}
			}
		}

		$startTime = microtime(TRUE);
		try {
			$response = $handler->handle($request);
		} catch (\Throwable $e) {
			$this->logService->logException($e, $request);
			$status = (int) $e->getCode();
			if ($status < 400 || $status > 599) {
				$status = 500;
			}

			$title = 'Internal Server Error';
			if ($status === 404) {
				$title = 'Not Found';
			} elseif ($status === 403) {
				$title = 'Forbidden';
			} elseif ($status === 401) {
				$title = 'Unauthorized';
			} elseif ($status === 400) {
				$title = 'Bad Request';
			}

			$legacyMode = $request->getAttribute('api.legacyMode');
			if ($legacyMode === NULL && ($request->getAttribute('api.isLegacy') || $request->getAttribute(
				'api.id'
			) === 'legacy')) {
				$legacyMode = new ApiLegacyMode();
			}

			$response = $this->responseService->createErrorResponse(
				$title,
				$e->getMessage(),
				$status,
				legacyMode: $legacyMode
			);
		}
		$duration = microtime(TRUE) - $startTime;
		$this->logService->logRequestResponse($request, $response, $duration);

		return $response->withHeader('X-Request-ID', $requestId);
	}
}
