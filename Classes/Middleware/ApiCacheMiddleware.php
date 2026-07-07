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

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use ReflectionException;
use SGalinski\SgApiCore\Attribute\ApiCache;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\PathAnalysisService;
use SGalinski\SgApiCore\Service\Router;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\Stream;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Middleware for API response caching
 */
class ApiCacheMiddleware implements MiddlewareInterface {
	/**
	 * @var FrontendInterface
	 */
	protected FrontendInterface $cache;

	/**
	 * @var PathAnalysisService
	 */
	protected PathAnalysisService $pathAnalysisService;

	/**
	 * @var ApiRegistry
	 */
	protected ApiRegistry $apiRegistry;

	/**
	 * @var Router
	 */
	protected Router $router;

	/**
	 * @var ExtensionConfiguration
	 */
	protected ExtensionConfiguration $extensionConfiguration;

	/**
	 * @var Context
	 */
	protected Context $context;

	/**
	 * @param ApiRegistry $apiRegistry
	 * @param Router $router
	 * @param PathAnalysisService $pathAnalysisService
	 * @param CacheManager $cacheManager
	 * @param ExtensionConfiguration $extensionConfiguration
	 * @param Context $context
	 * @throws NoSuchCacheException
	 */
	public function __construct(
		ApiRegistry $apiRegistry,
		Router $router,
		PathAnalysisService $pathAnalysisService,
		CacheManager $cacheManager,
		ExtensionConfiguration $extensionConfiguration,
		Context $context
	) {
		$this->apiRegistry = $apiRegistry;
		$this->router = $router;
		$this->pathAnalysisService = $pathAnalysisService;
		$this->extensionConfiguration = $extensionConfiguration;
		$this->context = $context;
		$this->cache = $cacheManager->getCache('sg_apicore_responses');
	}

	/**
	 * Process the middleware
	 *
	 * @param ServerRequestInterface $request
	 * @param RequestHandlerInterface $handler
	 * @return ResponseInterface
	 * @throws ReflectionException
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
		if (!$this->extensionConfiguration->isCacheEnabled()) {
			return $handler->handle($request);
		}

		// Skip if it contains legacy auth headers and is not already a legacy-mapped request
		$hasLegacyAuthHeader = $request->hasHeader('authtoken') || $request->hasHeader('bearertoken');
		if ($hasLegacyAuthHeader && !$request->getAttribute('api.isLegacy')) {
			return $handler->handle($request);
		}

		if ($request->getMethod() === 'GET') {
			return $this->handleGetRequest($request, $handler);
		}

		$response = $handler->handle($request);
		if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
			$this->handleInvalidation($request);
		}

		return $response;
	}

	/**
	 * Handles GET requests and caching
	 *
	 * @param ServerRequestInterface $request
	 * @param RequestHandlerInterface $handler
	 * @return ResponseInterface
	 * @throws ReflectionException
	 */
	protected function handleGetRequest(
		ServerRequestInterface $request,
		RequestHandlerInterface $handler
	): ResponseInterface {
		$apiId = $request->getAttribute('api.id');
		$version = $request->getAttribute('api.version');
		$path = $request->getAttribute('api.remainingPath');

		if (!$apiId || $path === NULL) {
			$analysis = $this->pathAnalysisService->analyze($request->getUri()->getPath());
			if ($analysis) {
				$apiId = $analysis['apiId'];
				$version = $analysis['version'];
				$path = $analysis['remainingPath'];

				$request = $request->withAttribute('api.id', $apiId);
				$request = $request->withAttribute('api.version', $version);
				$request = $request->withAttribute('api.remainingPath', $path);
			}
		}

		if (!$apiId || $version === NULL || $path === NULL) {
			return $handler->handle($request);
		}

		$matchingEndpoint = $this->findMatchingEndpoint($request, (string) $apiId, (string) $version, (string) $path);
		if ($matchingEndpoint === NULL) {
			return $handler->handle($request);
		}

		/** @var ApiCache|null $cacheAttr */
		$cacheAttr = $matchingEndpoint['apiCache'] ?? NULL;
		if ($cacheAttr === NULL) {
			// Caching by default for all GET requests if no attribute is present
			$cacheAttr = new ApiCache();
		}

		if (!$cacheAttr->enabled) {
			return $handler->handle($request);
		}

		if ($this->shouldBypassCacheRead($request)) {
			$response = $handler->handle($request);
			if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300 &&
				!$this->shouldSkipCacheStore($request)
			) {
				$cacheKey = $this->calculateCacheKey($request, $cacheAttr);
				$this->storeInCache($cacheKey, $response, $cacheAttr, $request);
			}

			return $response;
		}

		// Security Check: If the endpoint is protected, we MUST have a valid auth context
		$authMode = $matchingEndpoint['authMode'] ?? 'public';
		$isPublic = $authMode === 'public' || (\is_array($authMode) && \in_array('public', $authMode, TRUE));
		if (!$isPublic) {
			$authContext = $request->getAttribute('api.auth');
			if ($authContext === NULL) {
				// Not authenticated, let the request proceed to ApiRequestMiddleware to handle the error
				return $handler->handle($request);
			}
		}

		$cacheKey = $this->calculateCacheKey($request, $cacheAttr);
		$cachedResponse = $this->cache->get($cacheKey);

		if (\is_array($cachedResponse)) {
			$response = new Response();
			$stream = new Stream('php://temp', 'wb+');
			$stream->write($cachedResponse['body']);
			return $response->withStatus($cachedResponse['status'])
				->withHeader('Content-Type', $cachedResponse['contentType'])
				->withHeader('X-TYPO3-API-Cache', 'HIT')
				->withBody($stream);
		}

		$response = $handler->handle($request);

		if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300 &&
			!$this->shouldSkipCacheStore($request)
		) {
			$this->storeInCache($cacheKey, $response, $cacheAttr, $request);
		}

		return $response->withHeader('X-TYPO3-API-Cache', 'MISS');
	}

	/**
	 * Handles cache invalidation for writing requests
	 *
	 * @param ServerRequestInterface $request
	 * @throws ReflectionException
	 */
	protected function handleInvalidation(ServerRequestInterface $request): void {
		$apiId = $request->getAttribute('api.id');
		$version = $request->getAttribute('api.version');
		$path = $request->getAttribute('api.remainingPath');

		if (!$apiId || !$path) {
			$analysis = $this->pathAnalysisService->analyze($request->getUri()->getPath());
			if ($analysis) {
				$apiId = $analysis['apiId'];
				$version = $analysis['version'];
				$path = $analysis['remainingPath'];
			}
		}

		if (!$apiId || $version === NULL || !$path) {
			return;
		}

		$matchingEndpoint = $this->findMatchingEndpoint($request, (string) $apiId, (string) $version, (string) $path);
		if (!$matchingEndpoint) {
			return;
		}

		$tags = [];
		/** @var ApiCache|null $cacheAttr */
		$cacheAttr = $matchingEndpoint['apiCache'] ?? NULL;
		if ($cacheAttr && \count($cacheAttr->tags) > 0) {
			$tags = $cacheAttr->tags;
		}

		if (\count($tags) > 0) {
			$this->cache->flushByTags($tags);
		}
	}

	/**
	 * Calculates the cache key based on the request and cache configuration
	 *
	 * @param ServerRequestInterface $request
	 * @param ApiCache $cacheAttr
	 * @return string
	 */
	protected function calculateCacheKey(ServerRequestInterface $request, ApiCache $cacheAttr): string {
		$apiId = $request->getAttribute('api.id');
		$version = $request->getAttribute('api.version');
		if (!$apiId || !$version) {
			$analysis = $this->pathAnalysisService->analyze($request->getUri()->getPath());
			if ($analysis) {
				$apiId = $apiId ?? $analysis['apiId'];
				$version = $version ?? $analysis['version'];
			}
		}

		$queryParams = $request->getQueryParams();
		ksort($queryParams);

		$vary = [
			'uri' => (string) $request->getUri(),
			'queryParams' => $queryParams,
			'apiId' => $apiId,
			'version' => $version,
			'site' => $request->getAttribute('site')?->getIdentifier(),
			'tenant' => $request->getAttribute('api.tenant')?->getSiteRootPageId(),
		];

		if ($cacheAttr->useLanguage) {
			$vary['lang'] = $request->getAttribute('language')?->getLanguageId()
				?? $request->getAttribute('api.tenant')?->getLanguageId();
		}

		if ($cacheAttr->useUserGroups) {
			$usergroup = $request->getAttribute('frontend.user')?->user['usergroup'] ?? '';
			$groups = GeneralUtility::intExplode(',', (string) $usergroup, TRUE);
			sort($groups);
			$vary['userGroups'] = implode(',', $groups);
		}

		foreach ($cacheAttr->additionalVary as $item) {
			$headerLine = $request->getHeaderLine($item);
			$vary['extra_' . $item] = $request->getQueryParams()[$item] ?? ($headerLine);
		}

		return hash('sha256', serialize($vary));
	}

	/**
	 * Stores the response in the cache
	 *
	 * @param string $cacheKey
	 * @param ResponseInterface $response
	 * @param ApiCache $cacheAttr
	 * @param ServerRequestInterface $request
	 */
	protected function storeInCache(
		string $cacheKey,
		ResponseInterface $response,
		ApiCache $cacheAttr,
		ServerRequestInterface $request
	): void {
		$data = [
			'status' => $response->getStatusCode(),
			'contentType' => $response->getHeaderLine('Content-Type'),
			'body' => (string) $response->getBody(),
		];

		$tags = $cacheAttr->tags;
		$tags[] = 'api';
		if ($tenantId = $request->getAttribute('api.tenant')?->getSiteRootPageId()) {
			$tags[] = 'tenant_' . $tenantId;
		}

		$this->cache->set($cacheKey, $data, $tags, $cacheAttr->lifetime > 0 ? $cacheAttr->lifetime : NULL);
	}

	/**
	 * Returns the matched endpoint metadata for the current request.
	 *
	 * @param ServerRequestInterface $request
	 * @param string $apiId
	 * @param string $version
	 * @param string $path
	 * @return array<string, mixed>|null
	 * @throws ReflectionException
	 */
	protected function findMatchingEndpoint(
		ServerRequestInterface $request,
		string $apiId,
		string $version,
		string $path
	): ?array {
		$authMode = $this->resolveApiAuthMode($apiId, $version);
		$matchedRoute = $this->router->matchEndpoint($request, $apiId, $version, $path, $authMode);
		if (!\is_array($matchedRoute)) {
			return NULL;
		}

		$endpoint = $matchedRoute['endpoint'] ?? NULL;
		if (!\is_array($endpoint)) {
			return NULL;
		}

		$matchedAuthMode = $matchedRoute['authMode'] ?? NULL;
		$effectiveAuthMode = (!\is_array($matchedAuthMode) && $matchedAuthMode !== '')
			? $matchedAuthMode
			: $authMode;
		if (
			(!isset($endpoint['authMode']) || $endpoint['authMode'] === [] || $endpoint['authMode'] === '')
			&& $effectiveAuthMode !== NULL
		) {
			$endpoint['authMode'] = $effectiveAuthMode;
		}

		return $endpoint;
	}

	/**
	 * Resolves the effective API auth mode for endpoint matching.
	 *
	 * @param string $apiId
	 * @param string $version
	 * @return string|null
	 */
	protected function resolveApiAuthMode(string $apiId, string $version): ?string {
		$securityConfig = $this->apiRegistry->getSecurityConfig($apiId, $version);
		$authMode = $securityConfig['authMode'] ?? 'token';
		if (\is_array($authMode)) {
			$authMode = reset($authMode);
		}

		return \is_string($authMode) && $authMode !== '' ? $authMode : NULL;
	}

	/**
	 * Returns whether the current request should bypass cache reads.
	 *
	 * @param ServerRequestInterface $request
	 * @return bool
	 */
	protected function shouldBypassCacheRead(ServerRequestInterface $request): bool {
		if ($this->isBackendUserLoggedIn()) {
			return TRUE;
		}

		$cacheControl = strtolower($request->getHeaderLine('Cache-Control'));
		$pragma = strtolower($request->getHeaderLine('Pragma'));

		return str_contains($cacheControl, 'no-cache') ||
			str_contains($cacheControl, 'no-store') ||
			str_contains($pragma, 'no-cache');
	}

	/**
	 * Returns whether the current request should skip writing API cache entries.
	 *
	 * @param ServerRequestInterface $request
	 * @return bool
	 */
	protected function shouldSkipCacheStore(ServerRequestInterface $request): bool {
		if ($this->isBackendUserLoggedIn()) {
			return TRUE;
		}

		$cacheControl = strtolower($request->getHeaderLine('Cache-Control'));
		return str_contains($cacheControl, 'no-store');
	}

	/**
	 * Returns whether a backend user is actively logged in for the current request.
	 *
	 * @return bool
	 */
	protected function isBackendUserLoggedIn(): bool {
		return (bool) $this->context->getPropertyFromAspect('backend.user', 'isLoggedIn', FALSE);
	}
}
