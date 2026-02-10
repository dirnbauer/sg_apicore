<?php

/***************************************************************
 *  Copyright notice
 *  (c) sgalinski Internet Services (https://www.sgalinski.de)
 *
 *  All rights reserved
 *
 *  This file is part of the TYPO3 CMS project.
 *  It is free software; you can redistribute it and/or modify it under
 *  the terms of the GNU General Public License, either version 3
 *  of the License, or any later version.
 ***************************************************************/

namespace SGalinski\SgApiCore\Middleware;

use Doctrine\DBAL\Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\LogService;
use SGalinski\SgApiCore\Service\RateLimitService;
use SGalinski\SgApiCore\Service\ResourceRegistry;
use SGalinski\SgApiCore\Service\ResponseService;

/**
 * Middleware to enforce API rate limits
 */
class RateLimitMiddleware implements MiddlewareInterface {
	public function __construct(
		protected ExtensionConfiguration $extensionConfiguration,
		protected ApiRegistry $apiRegistry,
		protected ResourceRegistry $resourceRegistry,
		protected RateLimitService $rateLimitService,
		protected ResponseService $responseService,
		protected LogService $logService
	) {
	}

	/**
	 * @param ServerRequestInterface $request
	 * @param RequestHandlerInterface $handler
	 * @return ResponseInterface
	 * @throws Exception
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
		if (!$this->extensionConfiguration->isRateLimitEnabled()) {
			return $handler->handle($request);
		}

		$apiId = (string) $request->getAttribute('api.id');
		if ($apiId === '') {
			return $handler->handle($request);
		}

		$rateLimitConfig = $this->resolveRateLimitConfig($request, $apiId);
		if (is_array($rateLimitConfig) && array_key_exists(
			'enabled',
			$rateLimitConfig
		) && !$rateLimitConfig['enabled']) {
			return $handler->handle($request);
		}

		$limit = (int) ($rateLimitConfig['limit'] ?? $this->extensionConfiguration->getRateLimitDefaultLimit());
		$windowSeconds = (int) ($rateLimitConfig['windowSeconds'] ?? $this->extensionConfiguration->getRateLimitWindowSeconds(
		));
		$burst = (int) ($rateLimitConfig['burst'] ?? $this->extensionConfiguration->getRateLimitDefaultBurst());
		if ($limit <= 0 || $windowSeconds <= 0) {
			return $handler->handle($request);
		}

		$identifier = $this->buildIdentifier($request);
		$result = $this->rateLimitService->consume($identifier, $limit, $windowSeconds, $burst);

		if (!$result['allowed']) {
			$this->logService->logError('Rate limit exceeded.', [
				'identifier' => $identifier,
				'limit' => $result['limit'],
				'reset' => $result['reset']
			]);

			return $this->responseService->createErrorResponse(
				'Too Many Requests',
				'Rate limit exceeded.',
				429,
				additionalData: ['rateLimit' => $result]
			);
		}

		$request = $request->withAttribute('api.rateLimit', $result);
		$response = $handler->handle($request);

		$response = $response
			->withHeader('X-RateLimit-Limit', (string) $result['limit'])
			->withHeader('X-RateLimit-Remaining', (string) $result['remaining'])
			->withHeader('X-RateLimit-Reset', (string) $result['reset']);

		if ($result['burst'] > 0) {
			$response = $response->withHeader('X-RateLimit-Burst', (string) $result['burst']);
		}

		return $response;
	}

	/**
	 * @param ServerRequestInterface $request
	 * @return string
	 */
	protected function buildIdentifier(ServerRequestInterface $request): string {
		$apiId = (string) $request->getAttribute('api.id', 'global');
		$tenantId = $request->getAttribute('api.tenant')?->getTenantId() ?? 'none';
		$authContext = $request->getAttribute('api.auth');

		if ($authContext?->getTokenUid()) {
			$subject = 'token:' . $authContext->getTokenUid();
		} elseif ($authContext?->getUserId()) {
			$subject = 'user:' . $authContext->getUserId();
		} else {
			$subject = 'ip:' . $this->getClientIp($request);
		}

		return $apiId . ':' . $tenantId . ':' . $subject;
	}

	/**
	 * @param ServerRequestInterface $request
	 * @param string $apiId
	 * @return array|null
	 */
	protected function resolveRateLimitConfig(ServerRequestInterface $request, string $apiId): ?array {
		$resourceConfig = $this->resolveResourceConfig($request, $apiId);
		if (is_array($resourceConfig) && isset($resourceConfig['rateLimit']) && is_array(
			$resourceConfig['rateLimit']
		)) {
			return $resourceConfig['rateLimit'];
		}

		$version = (string) $request->getAttribute('api.version', '');
		return $this->apiRegistry->getRateLimitConfig($apiId, $version);
	}

	/**
	 * @param ServerRequestInterface $request
	 * @param string $apiId
	 * @return array|null
	 */
	protected function resolveResourceConfig(ServerRequestInterface $request, string $apiId): ?array {
		$remainingPath = (string) $request->getAttribute('api.remainingPath', '');
		if ($remainingPath === '') {
			return NULL;
		}

		$normalizedPath = '/' . trim($remainingPath, '/');
		if ($normalizedPath === '/') {
			return NULL;
		}

		foreach ($this->resourceRegistry->getResources($apiId) as $resource) {
			$basePath = '/' . trim((string) ($resource['basePath'] ?? ''), '/');
			if ($basePath !== '/' && ($normalizedPath === $basePath || str_starts_with(
				$normalizedPath,
				$basePath . '/'
			))) {
				return $resource;
			}
		}

		return NULL;
	}

	/**
	 * @param ServerRequestInterface $request
	 * @return string
	 */
	protected function getClientIp(ServerRequestInterface $request): string {
		$forwardedFor = $request->getHeaderLine('X-Forwarded-For');
		if ($forwardedFor !== '') {
			$parts = explode(',', $forwardedFor);
			$ip = trim($parts[0]);
			if ($ip !== '') {
				return $ip;
			}
		}

		$serverParams = $request->getServerParams();
		return $serverParams['REMOTE_ADDR'] ?? 'unknown';
	}
}
