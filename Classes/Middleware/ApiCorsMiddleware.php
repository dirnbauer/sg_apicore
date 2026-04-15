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
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\PathAnalysisService;
use TYPO3\CMS\Core\Http\Response;

/**
 * Handles CORS headers and preflight requests for API paths.
 */
class ApiCorsMiddleware implements MiddlewareInterface {
	protected const DEFAULT_ALLOW_METHODS = 'GET, POST, PUT, PATCH, DELETE, OPTIONS, HEAD';
	protected const DEFAULT_ALLOW_HEADERS = 'Authorization, Content-Type, Accept, Cache-Control, X-Requested-With';
	protected const DEFAULT_EXPOSE_HEADERS = 'X-Request-ID, X-RateLimit-Limit, X-RateLimit-Remaining, X-RateLimit-Reset, X-RateLimit-Burst';
	protected const DEFAULT_MAX_AGE = '86400';

	public function __construct(
		protected ExtensionConfiguration $extensionConfiguration,
		protected ApiRegistry $apiRegistry,
		protected PathAnalysisService $pathAnalysisService
	) {
	}

	/**
	 * @param ServerRequestInterface $request
	 * @param RequestHandlerInterface $handler
	 * @return ResponseInterface
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
		if (!$this->isApiRequest($request)) {
			return $handler->handle($request);
		}

		$policy = $this->resolveCorsPolicy($request);

		if (strtoupper($request->getMethod()) === 'OPTIONS') {
			if (trim($request->getHeaderLine('Access-Control-Request-Method')) === '') {
				return $handler->handle($request);
			}

			if (!$policy['allowed']) {
				return (new Response(NULL, 403))
					->withHeader('Vary', 'Origin');
			}

			return $this->applyCorsHeaders(new Response(NULL, 204), $request, $policy);
		}

		if (!$policy['allowed']) {
			return $handler->handle($request);
		}

		return $this->applyCorsHeaders($handler->handle($request), $request, $policy);
	}

	/**
	 * @param ServerRequestInterface $request
	 * @return bool
	 */
	protected function isApiRequest(ServerRequestInterface $request): bool {
		$path = $request->getUri()->getPath();
		$apiPathPrefix = '/' . trim($this->extensionConfiguration->getApiPathPrefix(), '/');

		return $path === $apiPathPrefix || str_starts_with($path, $apiPathPrefix . '/');
	}

	/**
	 * @param ResponseInterface $response
	 * @param ServerRequestInterface $request
	 * @param array $policy
	 * @return ResponseInterface
	 */
	protected function applyCorsHeaders(
		ResponseInterface $response,
		ServerRequestInterface $request,
		array $policy
	): ResponseInterface {
		$origin = trim($request->getHeaderLine('Origin'));
		$allowHeaders = $this->buildAllowHeaders($request);

		$response = $response
			->withHeader('Access-Control-Allow-Origin', $origin)
			->withHeader('Access-Control-Allow-Methods', $policy['allowMethods'])
			->withHeader('Access-Control-Allow-Headers', $allowHeaders)
			->withHeader('Access-Control-Expose-Headers', $policy['exposeHeaders'])
			->withHeader('Access-Control-Max-Age', self::DEFAULT_MAX_AGE);

		if ($policy['allowCredentials']) {
			$response = $response->withHeader('Access-Control-Allow-Credentials', 'true');
		}

		return $this->addVaryHeader($response, 'Origin');
	}

	/**
	 * @param ServerRequestInterface $request
	 * @return array{allowed: bool, allowCredentials: bool, allowMethods: string, exposeHeaders: string}
	 */
	protected function resolveCorsPolicy(ServerRequestInterface $request): array {
		$origin = trim($request->getHeaderLine('Origin'));
		if (!$this->isValidOrigin($origin)) {
			return [
				'allowed' => FALSE,
				'allowCredentials' => FALSE,
				'allowMethods' => self::DEFAULT_ALLOW_METHODS,
				'exposeHeaders' => self::DEFAULT_EXPOSE_HEADERS,
			];
		}

		$analysis = $this->pathAnalysisService->analyze($request->getUri()->getPath());
		if ($analysis === NULL || !$this->apiRegistry->hasApi($analysis['apiId'])) {
			return [
				'allowed' => FALSE,
				'allowCredentials' => FALSE,
				'allowMethods' => self::DEFAULT_ALLOW_METHODS,
				'exposeHeaders' => self::DEFAULT_EXPOSE_HEADERS,
			];
		}

		$securityConfig = $this->apiRegistry->getSecurityConfig($analysis['apiId'], (string) $analysis['version']);
		$corsConfig = \is_array($securityConfig['cors'] ?? NULL) ? $securityConfig['cors'] : [];
		$allowedOrigins = $this->normalizeAllowedOrigins($corsConfig['allowedOrigins'] ?? []);

		$isAllowed = \in_array($this->normalizeOrigin($origin), $allowedOrigins, TRUE);

		$allowMethods = self::DEFAULT_ALLOW_METHODS;
		if (\is_string($corsConfig['allowMethods'] ?? NULL) && trim((string) $corsConfig['allowMethods']) !== '') {
			$allowMethods = trim((string) $corsConfig['allowMethods']);
		}

		$exposeHeaders = self::DEFAULT_EXPOSE_HEADERS;
		if (\is_string($corsConfig['exposeHeaders'] ?? NULL) && trim((string) $corsConfig['exposeHeaders']) !== '') {
			$exposeHeaders = trim((string) $corsConfig['exposeHeaders']);
		}

		return [
			'allowed' => $isAllowed,
			'allowCredentials' => $isAllowed && (bool) ($corsConfig['allowCredentials'] ?? FALSE),
			'allowMethods' => $allowMethods,
			'exposeHeaders' => $exposeHeaders,
		];
	}

	/**
	 * @param string $origin
	 * @return bool
	 */
	protected function isValidOrigin(string $origin): bool {
		return $origin !== '' && (bool) preg_match('#^https?://[^/]+$#i', $origin);
	}

	/**
	 * @param array|string $allowedOrigins
	 * @return array<int, string>
	 */
	protected function normalizeAllowedOrigins(array|string $allowedOrigins): array {
		$origins = \is_array($allowedOrigins) ? $allowedOrigins : explode(',', (string) $allowedOrigins);
		$normalized = [];
		foreach ($origins as $origin) {
			if (!\is_string($origin)) {
				continue;
			}
			$origin = $this->normalizeOrigin($origin);
			if ($this->isValidOrigin($origin)) {
				$normalized[] = $origin;
			}
		}

		return array_values(array_unique($normalized));
	}

	/**
	 * @param string $origin
	 * @return string
	 */
	protected function normalizeOrigin(string $origin): string {
		return rtrim(trim($origin), '/');
	}

	/**
	 * @param ServerRequestInterface $request
	 * @return string
	 */
	protected function buildAllowHeaders(ServerRequestInterface $request): string {
		$allowedHeaders = array_filter(
			array_map('trim', explode(',', self::DEFAULT_ALLOW_HEADERS)),
			static fn (string $header): bool => $header !== ''
		);

		$requestedHeaderLine = $request->getHeaderLine('Access-Control-Request-Headers');
		if ($requestedHeaderLine !== '') {
			$requestedHeaders = array_filter(
				array_map('trim', explode(',', $requestedHeaderLine)),
				static fn (string $header): bool => $header !== '' && (bool) preg_match('/^[a-z0-9-]+$/i', $header)
			);
			$allowedHeaders = array_merge($allowedHeaders, $requestedHeaders);
		}

		$uniqueHeaders = [];
		$seen = [];
		foreach ($allowedHeaders as $header) {
			$normalized = strtolower($header);
			if (isset($seen[$normalized])) {
				continue;
			}
			$seen[$normalized] = TRUE;
			$uniqueHeaders[] = $header;
		}

		return implode(', ', $uniqueHeaders);
	}

	/**
	 * @param ResponseInterface $response
	 * @param string $headerName
	 * @return ResponseInterface
	 */
	protected function addVaryHeader(ResponseInterface $response, string $headerName): ResponseInterface {
		$varyHeaders = array_filter(
			array_map('trim', explode(',', $response->getHeaderLine('Vary'))),
			static fn (string $value): bool => $value !== ''
		);
		$normalizedExisting = array_map('strtolower', $varyHeaders);
		if (\in_array(strtolower($headerName), $normalizedExisting, TRUE)) {
			return $response;
		}

		$varyHeaders[] = $headerName;
		return $response->withHeader('Vary', implode(', ', $varyHeaders));
	}
}
