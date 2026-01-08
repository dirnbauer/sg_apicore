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
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Service\LogService;
use SGalinski\SgApiCore\Service\PathAnalysisService;
use SGalinski\SgApiCore\Service\Tenant\TenantResolverInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspectFactory;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\TypoScript\AST\Node\RootNode;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScript;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;
use TYPO3\CMS\Frontend\Page\PageInformation;

/**
 * Middleware to setup API request context (Tenant, TSFE, etc.)
 */
class ApiSetupMiddleware implements MiddlewareInterface {
	protected ExtensionConfiguration $extensionConfiguration;
	protected TenantResolverInterface $tenantResolver;
	protected PathAnalysisService $pathAnalysisService;
	protected LogService $logService;

	public function __construct(
		ExtensionConfiguration $extensionConfiguration,
		TenantResolverInterface $tenantResolver,
		PathAnalysisService $pathAnalysisService,
		LogService $logService
	) {
		$this->extensionConfiguration = $extensionConfiguration;
		$this->tenantResolver = $tenantResolver;
		$this->pathAnalysisService = $pathAnalysisService;
		$this->logService = $logService;
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
		/** @var \TYPO3\CMS\Core\Site\Entity\SiteLanguage $language */
		$language = $request->getAttribute('language');
		$languagePrefix = $language?->getBase()->getPath();
		if ($languagePrefix !== NULL && $languagePrefix !== '/' && $languagePrefix !== '') {
			$languagePrefix = '/' . trim($languagePrefix, '/') . '/';
			if (str_starts_with($path, $languagePrefix)) {
				$pathWithoutLanguage = '/' . ltrim(substr($path, strlen($languagePrefix)), '/');
				if (!str_starts_with($pathWithoutLanguage, $apiPathPrefix)) {
					return $handler->handle($request);
				}
			} elseif (!str_starts_with($path, $apiPathPrefix)) {
				return $handler->handle($request);
			}
		} elseif (!str_starts_with($path, $apiPathPrefix)) {
			return $handler->handle($request);
		}

		// Add Request ID
		$requestId = bin2hex(random_bytes(8));
		$request = $request->withAttribute('api.requestId', $requestId);

		// Resolve tenant early
		$tenantResult = $this->tenantResolver->resolve($request);
		if (!$tenantResult->isSuccess()) {
			return $this->createErrorResponse(
				'Tenant Resolution Failed',
				'Could not resolve a valid tenant for this request. Reason: ' . $tenantResult->getError(),
				$this->extensionConfiguration->getOnMissingTenantStatusCode()
			);
		}
		$request = $request->withAttribute('api.tenant', $tenantResult->getContext());

		// Initialize Language Aspect in Context
		if ($language) {
			$context = GeneralUtility::makeInstance(Context::class);
			$languageAspect = LanguageAspectFactory::createFromSiteLanguage($language);
			$context->setAspect('language', $languageAspect);

			// Ensure the language aspect is also reflected in the site attribute for some core processes
			$request = $request->withAttribute('language', $language);
		}

		// Analyze Path
		$analysis = $this->pathAnalysisService->analyze(
			$path, $languagePrefix ? $languagePrefix . ltrim($apiPathPrefix, '/') : NULL
		);
		if ($analysis) {
			$request = $request->withAttribute('api.id', $analysis['apiId']);
			$request = $request->withAttribute('api.version', $analysis['version']);
			$request = $request->withAttribute('api.remainingPath', $analysis['remainingPath']);
		}

		// Mock PageInformation for extensions like gridelements that expect it in TYPO3 13 frontend context
		$siteRootPageId = $tenantResult->getContext()?->getSiteRootPageId() ?? 0;
		if ($siteRootPageId > 0 && !$request->getAttribute('frontend.page.information')) {
			$pageInformation = new PageInformation();
			$pageInformation->setId($siteRootPageId);

			$rootline = [];
			try {
				$rootlineUtility = GeneralUtility::makeInstance(RootlineUtility::class, $siteRootPageId);
				$rootline = $rootlineUtility->get();
			} catch (\Throwable) {
				// Fallback if rootline cannot be generated
			}
			$pageInformation->setRootLine($rootline);

			$request = $request->withAttribute('frontend.page.information', $pageInformation);

			// Mock TSFE for Extbase ConfigurationManager in TYPO3 13
			if (!isset($GLOBALS['TSFE'])) {
				$site = $request->getAttribute('site');
				$language = $request->getAttribute('language');
				if ($site && $language) {
					$tsfe = GeneralUtility::makeInstance(
						TypoScriptFrontendController::class,
						$GLOBALS['TYPO3_CONF_VARS'],
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
					$tsfe->page = ['uid' => $siteRootPageId];
					$GLOBALS['TSFE'] = $tsfe;
				}
			}

			if (isset($GLOBALS['TYPO3_REQUEST'])) {
				$GLOBALS['TYPO3_REQUEST'] = $request;
			}
		}

		// Parse JSON Body if applicable
		if (str_contains($request->getHeaderLine('Content-Type'), 'application/json')) {
			$body = (string) $request->getBody();
			if ($body !== '') {
				try {
					$parsedBody = json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);
					if (is_array($parsedBody)) {
						$request = $request->withParsedBody($parsedBody);
					}
				} catch (\JsonException) {
				}
			}
		}

		$startTime = microtime(TRUE);
		try {
			$response = $handler->handle($request);
		} catch (\Throwable $e) {
			$this->logService->logException($e, $request);
			$response = $this->createErrorResponse(
				'Internal Server Error',
				'An unexpected error occurred during API request processing.',
				500
			);
		}
		$duration = microtime(TRUE) - $startTime;
		$this->logService->logRequestResponse($request, $response, $duration);

		return $response->withHeader('X-Request-ID', $requestId);
	}

	protected function createErrorResponse(string $title, string $detail, int $status): ResponseInterface {
		return new JsonResponse([
			'title' => $title,
			'detail' => $detail,
			'status' => $status,
			'type' => 'about:blank'
		], $status, ['Content-Type' => 'application/problem+json']);
	}
}
