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
use SGalinski\SgApiCore\Security\LoginProviderInterface;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\LogService;
use SGalinski\SgApiCore\Service\PathAnalysisService;

/**
 * Middleware to handle API authentication and scope validation
 */
class ApiAuthMiddleware implements MiddlewareInterface {
	protected ApiRegistry $apiRegistry;
	protected LoginProviderInterface $loginProvider;
	protected PathAnalysisService $pathAnalysisService;
	protected LogService $logService;

	public function __construct(
		ApiRegistry $apiRegistry,
		LoginProviderInterface $loginProvider,
		PathAnalysisService $pathAnalysisService,
		LogService $logService
	) {
		$this->apiRegistry = $apiRegistry;
		$this->loginProvider = $loginProvider;
		$this->pathAnalysisService = $pathAnalysisService;
		$this->logService = $logService;
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
		// Skip if it contains legacy auth headers and is not already a legacy-mapped request
		$hasLegacyAuthHeader = $request->hasHeader('authtoken') || $request->hasHeader('bearertoken');
		if ($hasLegacyAuthHeader && !$request->getAttribute('api.isLegacy')) {
			return $handler->handle($request);
		}

		$apiId = $request->getAttribute('api.id');
		$version = $request->getAttribute('api.version');

		// Fallback for path analysis if not already set by previous middleware
		if (!$apiId || !$version) {
			$analysis = $this->pathAnalysisService->analyze($request->getUri()->getPath());
			if ($analysis) {
				$apiId = $analysis['apiId'];
				$version = $analysis['version'];

				$request = $request->withAttribute('api.id', $apiId);
				$request = $request->withAttribute('api.version', $version);
				if (isset($analysis['remainingPath'])) {
					$request = $request->withAttribute('api.remainingPath', $analysis['remainingPath']);
				}
			}
		}

		if ($apiId && $version && $this->apiRegistry->hasApi($apiId)) {
			$apiConfig = $this->apiRegistry->getApi($apiId);
			if (in_array($version, $apiConfig['versions'], TRUE)) {
				$securityConfig = $this->apiRegistry->getSecurityConfig($apiId, $version);
				$activeProviders = $securityConfig['authProviders'] ?? [];

				$tenantId = $request->getAttribute('api.tenant')?->getTenantId() ?? '';

				// Authenticate (Token validation & Scope matching)
				$authContext = $this->loginProvider->authenticate(
					$request,
					$apiId,
					$tenantId,
					$activeProviders
				);

				if ($authContext !== NULL) {
					$request = $request->withAttribute('api.auth', $authContext);
				} elseif ($this->hasAuthToken($request)) {
					$request = $request->withAttribute('api.authError', 'Invalid or expired token.');
				}

				if (isset($GLOBALS['TYPO3_REQUEST'])) {
					$GLOBALS['TYPO3_REQUEST'] = $request;
				}
			}
		}

		return $handler->handle($request);
	}

	protected function hasAuthToken(ServerRequestInterface $request): bool {
		$authorizationHeader = $request->getHeaderLine('Authorization');
		if ($authorizationHeader !== '') {
			return TRUE;
		}

		return $request->hasHeader('authtoken') || $request->hasHeader('bearertoken');
	}
}
