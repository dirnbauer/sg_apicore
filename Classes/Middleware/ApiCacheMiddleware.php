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
use SGalinski\SgApiCore\Attribute\ApiCache;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Service\EndpointDiscoveryService;
use SGalinski\SgApiCore\Service\PathAnalysisService;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
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
	 * @var EndpointDiscoveryService
	 */
	protected EndpointDiscoveryService $discoveryService;

	/**
	 * @var ExtensionConfiguration
	 */
	protected ExtensionConfiguration $extensionConfiguration;

	/**
	 * @param EndpointDiscoveryService $discoveryService
	 * @param PathAnalysisService $pathAnalysisService
	 * @param CacheManager $cacheManager
	 * @param ExtensionConfiguration $extensionConfiguration
	 * @throws NoSuchCacheException
	 */
	public function __construct(
		EndpointDiscoveryService $discoveryService,
		PathAnalysisService $pathAnalysisService,
		CacheManager $cacheManager,
		ExtensionConfiguration $extensionConfiguration
	) {
		$this->discoveryService = $discoveryService;
		$this->pathAnalysisService = $pathAnalysisService;
		$this->extensionConfiguration = $extensionConfiguration;
		$this->cache = $cacheManager->getCache('sg_apicore_responses');
	}

	/**
	 * Process the middleware
	 *
	 * @param ServerRequestInterface $request
	 * @param RequestHandlerInterface $handler
	 * @return ResponseInterface
	 * @throws \ReflectionException
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
	 * @throws \ReflectionException
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

		if (!$apiId || $path === NULL) {
			return $handler->handle($request);
		}

		$endpoints = $this->discoveryService->getEndpointsForApi($apiId, $version);
		$matchingEndpoint = NULL;
		foreach ($endpoints as $endpoint) {
			if ($endpoint['path'] === $path && \in_array('GET', $endpoint['methods'], TRUE)) {
				$matchingEndpoint = $endpoint;
				break;
			}
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

		if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
			$this->storeInCache($cacheKey, $response, $cacheAttr, $request);
		}

		return $response->withHeader('X-TYPO3-API-Cache', 'MISS');
	}

	/**
	 * Handles cache invalidation for writing requests
	 *
	 * @param ServerRequestInterface $request
	 * @throws \ReflectionException
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

		if (!$apiId || !$path) {
			return;
		}

		$endpoints = $this->discoveryService->getEndpointsForApi($apiId, $version);
		$matchingEndpoint = NULL;
		foreach ($endpoints as $endpoint) {
			if ($endpoint['path'] === $path && \in_array($request->getMethod(), $endpoint['methods'], TRUE)) {
				$matchingEndpoint = $endpoint;
				break;
			}
		}

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
			$vary['extra_' . $item] = $request->getQueryParams()[$item] ?? ($headerLine !== '' ? $headerLine : '');
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
}
