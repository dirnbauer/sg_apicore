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
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Security\LoginProviderInterface;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\LogService;
use SGalinski\SgApiCore\Service\Router;
use SGalinski\SgApiCore\Service\Tenant\TenantResolverInterface;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;

/**
 * Middleware to handle API requests
 */
class ApiRequestMiddleware implements MiddlewareInterface {
	/**
	 * @var ExtensionConfiguration
	 */
	protected ExtensionConfiguration $extensionConfiguration;

	/**
	 * @var ApiRegistry
	 */
	protected ApiRegistry $apiRegistry;

	/**
	 * @var Router
	 */
	protected Router $router;

	/**
	 * @var TenantResolverInterface
	 */
	protected TenantResolverInterface $tenantResolver;

	/**
	 * @var LoginProviderInterface
	 */
	protected LoginProviderInterface $loginProvider;

	/**
	 * @var LogService
	 */
	protected LogService $logService;

	/**
	 * @param ExtensionConfiguration $extensionConfiguration
	 * @param ApiRegistry $apiRegistry
	 * @param Router $router
	 * @param TenantResolverInterface $tenantResolver
	 * @param LoginProviderInterface $loginProvider
	 * @param LogService $logService
	 */
	public function __construct(
		ExtensionConfiguration $extensionConfiguration,
		ApiRegistry $apiRegistry,
		Router $router,
		TenantResolverInterface $tenantResolver,
		LoginProviderInterface $loginProvider,
		LogService $logService
	) {
		$this->extensionConfiguration = $extensionConfiguration;
		$this->apiRegistry = $apiRegistry;
		$this->router = $router;
		$this->tenantResolver = $tenantResolver;
		$this->loginProvider = $loginProvider;
		$this->logService = $logService;
	}

	/**
	 * Process an incoming server request.
	 *
	 * @param ServerRequestInterface $request
	 * @param RequestHandlerInterface $handler
	 * @return ResponseInterface
	 * @throws \ReflectionException
	 * @throws \Exception
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
		$uri = $request->getUri();
		$path = $uri->getPath();
		$apiPathPrefix = $this->extensionConfiguration->getApiPathPrefix();
		if (!str_starts_with($path, $apiPathPrefix)) {
			return $handler->handle($request);
		}

		// Add Request ID
		$requestId = bin2hex(random_bytes(8));
		$request = $request->withAttribute('api.requestId', $requestId);

		$startTime = microtime(TRUE);
		$response = $this->handleApiRequest($request, $handler);
		$duration = microtime(TRUE) - $startTime;

		$this->logService->logRequestResponse($request, $response, $duration);

		return $response->withHeader('X-Request-ID', $requestId);
	}

	/**
	 * Core API request handling logic
	 *
	 * @param ServerRequestInterface $request
	 * @param RequestHandlerInterface $handler
	 * @return ResponseInterface
	 * @throws \ReflectionException
	 */
	protected function handleApiRequest(
		ServerRequestInterface $request,
		RequestHandlerInterface $handler
	): ResponseInterface {
		$path = $request->getUri()->getPath();
		$apiPathPrefix = $this->extensionConfiguration->getApiPathPrefix();

		// Resolve tenant early
		$tenantResult = $this->tenantResolver->resolve($request);
		if (!$tenantResult->isSuccess()) {
			return $this->createErrorResponse(
				'Tenant Resolution Failed',
				'Could not resolve a valid tenant for this request. Reason: ' . $tenantResult->getError(),
				$this->extensionConfiguration->getOnMissingTenantStatusCode()
			);
		}
		$request = $request->withAttribute('api.tenant', $tenantResult->getContext());

		// Normalize a path for internal processing: remove trailing slash if it's not just '/'
		$normalizedPath = $path !== '/' ? rtrim($path, '/') : $path;

		// basic API health check
		if ($normalizedPath === rtrim($apiPathPrefix, '/')) {
			return new JsonResponse(['status' => 'ok']);
		}

		if ($normalizedPath === rtrim($apiPathPrefix, '/') . '/health') {
			return new JsonResponse(['status' => 'ok']);
		}

		// Pattern: /api/{apiId}/v{version}/{remainingPath}
		$relativeWeight = strlen($apiPathPrefix);
		$relativeRequestPath = substr($path, $relativeWeight);
		// Normalize a relative path: remove the trailing slash but keep it if it's empty to allow regex match
		$relativeRequestPath = rtrim($relativeRequestPath, '/');

		// Regex to match {apiId}/v(\d+)(/.*)?
		if (preg_match('#^([^/]+)/v(\d+)(/.*)?$#', $relativeRequestPath, $matches)) {
			$apiId = $matches[1];
			/** @noinspection MultiAssignmentUsageInspection */
			$version = $matches[2];
			$remainingPath = $matches[3] ?? '/';
			// Normalize the remaining path for the router
			$remainingPath = $remainingPath !== '/' ? rtrim($remainingPath, '/') : $remainingPath;
			if ($remainingPath === '') {
				$remainingPath = '/';
			}

			if ($this->apiRegistry->hasApi($apiId)) {
				$request = $request->withAttribute('api.id', $apiId);
				$apiConfig = $this->apiRegistry->getApi($apiId);
				if (in_array($version, $apiConfig['versions'], TRUE)) {
					// Get security config
					$securityConfig = $this->apiRegistry->getSecurityConfig($apiId, $version);
					$authMode = $securityConfig['authMode'] ?? 'token';
					$activeProviders = $securityConfig['authProviders'] ?? [];

					// Authenticate
					$authContext = $this->loginProvider->authenticate(
						$request,
						$apiId,
						$tenantResult->getContext()?->getTenantId(),
						$activeProviders
					);

					// Documentation endpoints should always be accessible without authentication
					if ($authContext !== NULL) {
						$request = $request->withAttribute('api.auth', $authContext);
					}

					// Redirect to documentation if the base API URL is called
					if ($remainingPath === '/' && $request->getMethod() === 'GET') {
						$redirectPath = rtrim($path, '/') . '/docs/ui';
						return new RedirectResponse($redirectPath);
					}

					return $this->router->dispatch($request, $apiId, $version, $remainingPath, $authMode);
				}
			}
		}

		return $this->createErrorResponse('Not Found', 'The requested API or version does not exist.', 404);
	}

	/**
	 * Creates a Problem JSON error response (RFC 7807)
	 *
	 * @param string $title
	 * @param string $detail
	 * @param int $status
	 * @return ResponseInterface
	 */
	protected function createErrorResponse(string $title, string $detail, int $status): ResponseInterface {
		return new JsonResponse([
			'title' => $title,
			'detail' => $detail,
			'status' => $status,
			'type' => 'about:blank'
		], $status, ['Content-Type' => 'application/problem+json']);
	}
}
