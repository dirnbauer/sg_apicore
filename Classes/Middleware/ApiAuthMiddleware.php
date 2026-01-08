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
use SGalinski\SgApiCore\Security\LoginProviderInterface;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\LogService;
use SGalinski\SgApiCore\Service\PathAnalysisService;
use TYPO3\CMS\Core\Http\JsonResponse;

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
		$apiId = $request->getAttribute('api.id');
		$version = $request->getAttribute('api.version');

		// Fallback for path analysis if not already set by previous middleware
		if (!$apiId || !$version) {
			$analysis = $this->pathAnalysisService->analyze($request->getUri()->getPath());
			if ($analysis) {
				$apiId = $analysis['apiId'];
				$version = $analysis['version'];
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
				}

				if (isset($GLOBALS['TYPO3_REQUEST'])) {
					$GLOBALS['TYPO3_REQUEST'] = $request;
				}
			}
		}

		return $handler->handle($request);
	}
}
