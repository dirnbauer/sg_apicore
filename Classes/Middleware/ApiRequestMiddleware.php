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

namespace SGalinski\SgApiCore\Middleware;

use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use ReflectionException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SGalinski\SgApiCore\Attribute\ApiLegacyMode;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\PathAnalysisService;
use SGalinski\SgApiCore\Service\ResponseService;
use SGalinski\SgApiCore\Service\Router;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;
use TYPO3\CMS\Extbase\Persistence\PersistenceManagerInterface;

/**
 * Middleware to handle API request dispatching
 */
class ApiRequestMiddleware implements MiddlewareInterface {
	protected ExtensionConfiguration $extensionConfiguration;
	protected ApiRegistry $apiRegistry;
	protected Router $router;
	protected PathAnalysisService $pathAnalysisService;
	protected ResponseService $responseService;
	protected PersistenceManagerInterface $persistenceManager;

	public function __construct(
		ExtensionConfiguration $extensionConfiguration,
		ApiRegistry $apiRegistry,
		Router $router,
		PathAnalysisService $pathAnalysisService,
		ResponseService $responseService,
		PersistenceManagerInterface $persistenceManager
	) {
		$this->extensionConfiguration = $extensionConfiguration;
		$this->apiRegistry = $apiRegistry;
		$this->router = $router;
		$this->pathAnalysisService = $pathAnalysisService;
		$this->responseService = $responseService;
		$this->persistenceManager = $persistenceManager;
	}

	/**
     * Process an incoming server request.
     *
     * @param ServerRequestInterface $request
     * @param RequestHandlerInterface $handler
     * @return ResponseInterface
     * @throws ReflectionException
     */
    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
		$uri = $request->getUri();
		$path = $uri->getPath();
		$apiPathPrefix = $this->extensionConfiguration->getApiPathPrefix();

		// Respect TYPO3 Language Prefix
		$language = $request->getAttribute('language');
		$languagePrefix = NULL;
		if ($language instanceof SiteLanguage) {
			$languagePrefix = $language->getBase()->getPath();
		}

		$pathWithoutLanguage = $path;
		if ($languagePrefix !== NULL && $languagePrefix !== '/' && $languagePrefix !== '') {
			$languagePrefix = '/' . trim($languagePrefix, '/') . '/';
			if (str_starts_with($path, $languagePrefix)) {
				$pathWithoutLanguage = '/' . ltrim(substr($path, strlen($languagePrefix)), '/');
			}
		}

		if (!str_starts_with($pathWithoutLanguage, $apiPathPrefix)) {
			return $handler->handle($request);
		}

		// Skip if it contains legacy auth headers and is not yet a legacy-mapped request
		$hasLegacyAuthHeader = $request->hasHeader('authtoken') || $request->hasHeader('bearertoken');
		if ($hasLegacyAuthHeader && !$request->getAttribute('api.isLegacy')) {
			return $handler->handle($request);
		}

		// Health Check
		$normalizedPath = $pathWithoutLanguage !== '/' ? rtrim($pathWithoutLanguage, '/') : $pathWithoutLanguage;

		// basic API health check
		if ($normalizedPath === rtrim($apiPathPrefix, '/')) {
			return new JsonResponse(['status' => 'ok']);
		}

		if ($normalizedPath === rtrim($apiPathPrefix, '/') . '/health') {
			return new JsonResponse(['status' => 'ok']);
		}

		$apiId = $request->getAttribute('api.id');
		$version = $request->getAttribute('api.version');
		$remainingPath = $request->getAttribute('api.remainingPath');

		// Fallback for path analysis if not already set by previous middleware
		if (!$apiId || !$version) {
			$analysis = $this->pathAnalysisService->analyze(
				$path,
				$languagePrefix ? $languagePrefix . ltrim($apiPathPrefix, '/') : NULL
			);
			if ($analysis) {
				$apiId = $analysis['apiId'];
				$version = $analysis['version'];
				$remainingPath = $analysis['remainingPath'];
			}
		}

		if ($apiId && $version && $this->apiRegistry->hasApi($apiId)) {
			$apiConfig = $this->apiRegistry->getApi($apiId);
			if (in_array($version, $apiConfig['versions'], TRUE)) {
				// Redirect to documentation if the base API URL is called
				if ($remainingPath === '/' && $request->getMethod() === 'GET') {
					$redirectPath = rtrim($path, '/') . '/docs/ui';
					if (!str_ends_with($redirectPath, '/docs/ui')) {
						$redirectPath .= '/docs/ui';
					}
					return new RedirectResponse($redirectPath);
				}

				$securityConfig = $this->apiRegistry->getSecurityConfig($apiId, $version);
				$authMode = $securityConfig['authMode'] ?? 'token';
				if (is_array($authMode)) {
					$authMode = (string) reset($authMode);
				}
				$authMode = (string) $authMode;

				$response = $this->router->dispatch($request, $apiId, $version, $remainingPath, $authMode);
				$this->persistenceManager->persistAll();
				return $response;
			}
		}

		return $this->createErrorResponse('Not Found', 'The requested API or version does not exist.', 404, $request);
	}

	/**
	 * Creates a Problem JSON error response (RFC 7807)
	 *
	 * @param string $title
	 * @param string $detail
	 * @param int $status
	 * @param ServerRequestInterface|null $request
	 * @return ResponseInterface
	 */
	protected function createErrorResponse(
		string $title,
		string $detail,
		int $status,
		?ServerRequestInterface $request = NULL
	): ResponseInterface {
		$legacyMode = $request?->getAttribute('api.legacyMode');
		if ($legacyMode === NULL && ($request?->getAttribute('api.isLegacy') || $request?->getAttribute(
			'api.id'
		) === 'legacy')) {
			$legacyMode = new ApiLegacyMode();
		}

		return $this->responseService->createErrorResponse($title, $detail, $status, legacyMode: $legacyMode);
	}
}
