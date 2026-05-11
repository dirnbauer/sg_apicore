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
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\ApiTypoScriptSetupService;
use SGalinski\SgApiCore\Service\Router;

/**
 * Initializes TypoScript only when required by an endpoint
 */
readonly class ApiTypoScriptMiddleware implements MiddlewareInterface {
	public function __construct(
		protected ApiRegistry $apiRegistry,
		protected Router $router,
		protected ApiTypoScriptSetupService $typoScriptSetupService
	) {
	}

	/**
	 * @param ServerRequestInterface $request
	 * @param RequestHandlerInterface $handler
	 * @return ResponseInterface
	 * @throws ReflectionException
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
		$apiId = (string) $request->getAttribute('api.id');
		$version = (string) $request->getAttribute('api.version');
		$path = $request->getAttribute('api.remainingPath');

		if ($apiId === '' || $version === '' || $path === NULL) {
			return $handler->handle($request);
		}

		$securityConfig = $this->apiRegistry->getSecurityConfig($apiId, $version);
		$authMode = $securityConfig['authMode'] ?? 'token';
		if (\is_array($authMode)) {
			$authMode = (string) reset($authMode);
		}
		$authMode = (string) $authMode;

		$handlerInfo = $this->router->matchEndpoint($request, $apiId, $version, (string) $path, $authMode);
		if (!\is_array($handlerInfo)) {
			return $handler->handle($request);
		}

		if (empty($handlerInfo['endpoint']['requireFullTypoScript'])) {
			return $handler->handle($request);
		}

		$request = $request->withAttribute('api.requireFullTypoScript', TRUE);
		$request = $this->typoScriptSetupService->ensureTypoScript($request, $request->getAttribute('api.tenant'));

		return $handler->handle($request);
	}
}
