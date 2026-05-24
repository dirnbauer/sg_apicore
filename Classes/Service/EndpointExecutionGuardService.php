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
use SGalinski\SgApiCore\Context\TenantContext;
use SGalinski\SgApiCore\Security\AuthContext;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Shared endpoint execution guards used by HTTP middleware and internal dispatches.
 *
 * @phpstan-type RateLimitState array{allowed?: bool, limit: int, remaining: int, reset: int, burst?: int}
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
	 * @return array{request: ServerRequestInterface, response: ResponseInterface|null, rateLimit: RateLimitState|null}
	 * @throws Exception
	 */
	public function enforceRateLimit(ServerRequestInterface $request): array {
		if (!$this->extensionConfiguration->isRateLimitEnabled()) {
			return $this->createAllowedResult($request);
		}

		$apiIdAttribute = $request->getAttribute('api.id', '');
		$apiId = \is_scalar($apiIdAttribute) ? (string) $apiIdAttribute : '';
		if ($apiId === '') {
			return $this->createAllowedResult($request);
		}

		$rateLimitConfig = $this->resolveRateLimitConfig($request, $apiId);
		if (\is_array($rateLimitConfig) && \array_key_exists('enabled', $rateLimitConfig) && !$rateLimitConfig['enabled']) {
			return $this->createAllowedResult($request);
		}

		$limit = $this->resolvePositiveInt($rateLimitConfig['limit'] ?? NULL, $this->extensionConfiguration->getRateLimitDefaultLimit());
		$windowSeconds = $this->resolvePositiveInt(
			$rateLimitConfig['windowSeconds'] ?? NULL,
			$this->extensionConfiguration->getRateLimitWindowSeconds()
		);
		$burst = $this->resolvePositiveInt($rateLimitConfig['burst'] ?? NULL, $this->extensionConfiguration->getRateLimitDefaultBurst());
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
	 * @param RateLimitState|null $rateLimit
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
	 */
	protected function buildIdentifier(ServerRequestInterface $request): string {
		$apiIdAttribute = $request->getAttribute('api.id', 'global');
		$apiId = \is_scalar($apiIdAttribute) ? (string) $apiIdAttribute : 'global';
		$tenantContext = $request->getAttribute('api.tenant');
		$tenantId = $tenantContext instanceof TenantContext ? $tenantContext->getTenantId() : 'none';
		$authContext = $request->getAttribute('api.auth');

		if ($authContext instanceof AuthContext && $authContext->getTokenUid()) {
			$subject = 'token:' . $authContext->getTokenUid();
		} elseif ($authContext instanceof AuthContext && $authContext->getUserId()) {
			$subject = 'user:' . $authContext->getUserId();
		} else {
			$subject = 'ip:' . $this->getClientIp($request);
		}

		return $apiId . ':' . $tenantId . ':' . $subject;
	}

	/**
	 * @param ServerRequestInterface $request
	 * @param string $apiId
	 * @return array<string, mixed>|null
	 */
	protected function resolveRateLimitConfig(ServerRequestInterface $request, string $apiId): ?array {
		$resourceConfig = $this->resolveResourceConfig($request, $apiId);
		if (\is_array($resourceConfig) && isset($resourceConfig['rateLimit']) && \is_array($resourceConfig['rateLimit'])) {
			return $this->normalizeStringKeyArray($resourceConfig['rateLimit']);
		}

		$versionAttribute = $request->getAttribute('api.version', '');
		$version = \is_scalar($versionAttribute) ? (string) $versionAttribute : '';
		$rateLimitConfig = $this->apiRegistry->getRateLimitConfig($apiId, $version);
		return \is_array($rateLimitConfig) ? $this->normalizeStringKeyArray($rateLimitConfig) : NULL;
	}

	/**
	 * @param ServerRequestInterface $request
	 * @param string $apiId
	 * @return array<string, mixed>|null
	 */
	protected function resolveResourceConfig(ServerRequestInterface $request, string $apiId): ?array {
		$remainingPathAttribute = $request->getAttribute('api.remainingPath', '');
		$remainingPath = \is_scalar($remainingPathAttribute) ? (string) $remainingPathAttribute : '';
		if ($remainingPath === '') {
			return NULL;
		}

		$normalizedPath = '/' . trim($remainingPath, '/');
		if ($normalizedPath === '/') {
			return NULL;
		}

		foreach ($this->resourceRegistry->getResources($apiId) as $resource) {
			if (!\is_array($resource)) {
				continue;
			}
			$basePathValue = $resource['basePath'] ?? '';
			$basePath = '/' . trim(\is_scalar($basePathValue) ? (string) $basePathValue : '', '/');
			if ($basePath !== '/' && ($normalizedPath === $basePath || str_starts_with($normalizedPath, $basePath . '/'))) {
				return $this->normalizeStringKeyArray($resource);
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
		$remoteAddress = $serverParams['REMOTE_ADDR'] ?? 'unknown';
		return \is_scalar($remoteAddress) ? (string) $remoteAddress : 'unknown';
	}

	protected function resolvePositiveInt(mixed $value, int $default): int {
		if (\is_int($value)) {
			return $value;
		}
		if (\is_string($value) && is_numeric($value)) {
			return (int) $value;
		}
		return $default;
	}

	/**
	 * @param array<mixed> $values
	 * @return array<string, mixed>
	 */
	protected function normalizeStringKeyArray(array $values): array {
		$normalized = [];
		foreach ($values as $key => $value) {
			if (\is_string($key)) {
				$normalized[$key] = $value;
			}
		}
		return $normalized;
	}
}
