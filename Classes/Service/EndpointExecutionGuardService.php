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

namespace SGalinski\SgApiCore\Service;

use Doctrine\DBAL\Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Shared endpoint execution guards used by HTTP middleware and internal dispatches.
 */
class EndpointExecutionGuardService implements SingletonInterface {
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
	 * Applies the configured rate limit for the target API/resource.
	 *
	 * @param ServerRequestInterface $request
	 * @return array{request: ServerRequestInterface, response: ResponseInterface|null, rateLimit: array|null}
	 * @throws Exception
	 */
	public function enforceRateLimit(ServerRequestInterface $request): array {
		if (!$this->extensionConfiguration->isRateLimitEnabled()) {
			return $this->createAllowedResult($request);
		}

		$apiId = (string) $request->getAttribute('api.id');
		if ($apiId === '') {
			return $this->createAllowedResult($request);
		}

		$rateLimitConfig = $this->resolveRateLimitConfig($request, $apiId);
		if (\is_array($rateLimitConfig) && \array_key_exists('enabled', $rateLimitConfig) && !$rateLimitConfig['enabled']) {
			return $this->createAllowedResult($request);
		}

		$limit = (int) ($rateLimitConfig['limit'] ?? $this->extensionConfiguration->getRateLimitDefaultLimit());
		$windowSeconds = (int) ($rateLimitConfig['windowSeconds'] ?? $this->extensionConfiguration->getRateLimitWindowSeconds());
		$burst = (int) ($rateLimitConfig['burst'] ?? $this->extensionConfiguration->getRateLimitDefaultBurst());
		if ($limit <= 0 || $windowSeconds <= 0) {
			return $this->createAllowedResult($request);
		}

		$identifier = $this->buildIdentifier($request);
		$result = $this->rateLimitService->consume($identifier, $limit, $windowSeconds, $burst);

		if (!$result['allowed']) {
			$this->logService->logError('Rate limit exceeded.', [
				'identifier' => $identifier,
				'limit' => $result['limit'],
				'reset' => $result['reset'],
			]);

			return [
				'request' => $request,
				'response' => $this->responseService->createErrorResponse(
					'Too Many Requests',
					'Rate limit exceeded.',
					429,
					additionalData: ['rateLimit' => $result]
				),
				'rateLimit' => $result,
			];
		}

		return [
			'request' => $request->withAttribute('api.rateLimit', $result),
			'response' => NULL,
			'rateLimit' => $result,
		];
	}

	/**
	 * Adds rate-limit response headers if a rate limit was consumed.
	 *
	 * @param ResponseInterface $response
	 * @param array|null $rateLimit
	 * @return ResponseInterface
	 */
	public function applyRateLimitHeaders(ResponseInterface $response, ?array $rateLimit): ResponseInterface {
		if ($rateLimit === NULL) {
			return $response;
		}

		$response = $response
			->withHeader('X-RateLimit-Limit', (string) $rateLimit['limit'])
			->withHeader('X-RateLimit-Remaining', (string) $rateLimit['remaining'])
			->withHeader('X-RateLimit-Reset', (string) $rateLimit['reset']);

		if (($rateLimit['burst'] ?? 0) > 0) {
			$response = $response->withHeader('X-RateLimit-Burst', (string) $rateLimit['burst']);
		}

		return $response;
	}

	/**
	 * @param ServerRequestInterface $request
	 * @return array{request: ServerRequestInterface, response: null, rateLimit: null}
	 */
	protected function createAllowedResult(ServerRequestInterface $request): array {
		return [
			'request' => $request,
			'response' => NULL,
			'rateLimit' => NULL,
		];
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
		if (\is_array($resourceConfig) && isset($resourceConfig['rateLimit']) && \is_array($resourceConfig['rateLimit'])) {
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
			if ($basePath !== '/' && ($normalizedPath === $basePath || str_starts_with($normalizedPath, $basePath . '/'))) {
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
