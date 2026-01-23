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
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\ApiTypoScriptSetupService;
use SGalinski\SgApiCore\Service\Router;

/**
 * Initializes TypoScript only when required by an endpoint
 */
class ApiTypoScriptMiddleware implements MiddlewareInterface {
	public function __construct(
		protected readonly ApiRegistry $apiRegistry,
		protected readonly Router $router,
		protected readonly ApiTypoScriptSetupService $typoScriptSetupService
	) {
	}

	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
		$apiId = (string) $request->getAttribute('api.id');
		$version = (string) $request->getAttribute('api.version');
		$path = $request->getAttribute('api.remainingPath');

		if ($apiId === '' || $version === '' || $path === NULL) {
			return $handler->handle($request);
		}

		$securityConfig = $this->apiRegistry->getSecurityConfig($apiId, $version);
		$authMode = $securityConfig['authMode'] ?? 'token';

		$handlerInfo = $this->router->matchEndpoint($request, $apiId, $version, (string) $path, $authMode);
		if (!is_array($handlerInfo)) {
			return $handler->handle($request);
		}

		if (empty($handlerInfo['endpoint']['requireFullTypoScript'])) {
			return $handler->handle($request);
		}

		$request = $request->withAttribute('api.requireFullTypoScript', TRUE);
		$request = $this->typoScriptSetupService->ensureTypoScript(
			$request,
			$request->getAttribute('api.tenant')
		);

		return $handler->handle($request);
	}
}
