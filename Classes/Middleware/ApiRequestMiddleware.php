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
use SGalinski\SgApiCore\Attribute\ApiLegacyMode;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\PathAnalysisService;
use SGalinski\SgApiCore\Service\Router;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Http\RedirectResponse;

/**
 * Middleware to handle API request dispatching
 */
class ApiRequestMiddleware implements MiddlewareInterface {
	protected ExtensionConfiguration $extensionConfiguration;
	protected ApiRegistry $apiRegistry;
	protected Router $router;
	protected PathAnalysisService $pathAnalysisService;

	public function __construct(
		ExtensionConfiguration $extensionConfiguration,
		ApiRegistry $apiRegistry,
		Router $router,
		PathAnalysisService $pathAnalysisService
	) {
		$this->extensionConfiguration = $extensionConfiguration;
		$this->apiRegistry = $apiRegistry;
		$this->router = $router;
		$this->pathAnalysisService = $pathAnalysisService;
	}

	/**
	 * Process an incoming server request.
	 *
	 * @param ServerRequestInterface $request
	 * @param RequestHandlerInterface $handler
	 * @return ResponseInterface
	 * @throws \ReflectionException
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
		$uri = $request->getUri();
		$path = $uri->getPath();
		$apiPathPrefix = $this->extensionConfiguration->getApiPathPrefix();

		// Respect TYPO3 Language Prefix
		/** @var \TYPO3\CMS\Core\Site\Entity\SiteLanguage $language */
		$language = $request->getAttribute('language');
		$languagePrefix = $language?->getBase()->getPath();
		$pathWithoutLanguage = $path;
		if ($languagePrefix !== NULL && $languagePrefix !== '/' && $languagePrefix !== '') {
			$languagePrefix = '/' . trim($languagePrefix, '/') . '/';
			if (str_starts_with($path, $languagePrefix)) {
				$pathWithoutLanguage = '/' . ltrim(substr($path, strlen($languagePrefix)), '/');
			}
		}

		if (!str_starts_with($pathWithoutLanguage, $apiPathPrefix)) {
			return $handler->handle($request);
		}

		// Health Check
		$normalizedPath = $pathWithoutLanguage !== '/' ? rtrim($pathWithoutLanguage, '/') : $pathWithoutLanguage;

		// basic API health check
		if ($normalizedPath === rtrim($apiPathPrefix, '/')) {
			return new JsonResponse(['status' => 'ok']);
		}

		if ($normalizedPath === rtrim($apiPathPrefix, '/') . '/health') {
			return new JsonResponse(['status' => 'ok']);
		}

		$apiId = $request->getAttribute('api.id');
		$version = $request->getAttribute('api.version');
		$remainingPath = $request->getAttribute('api.remainingPath');

		// Fallback for path analysis if not already set by previous middleware
		if (!$apiId || !$version) {
			$analysis = $this->pathAnalysisService->analyze($path, $languagePrefix ? $languagePrefix . ltrim($apiPathPrefix, '/') : NULL);
			if ($analysis) {
				$apiId = $analysis['apiId'];
				$version = $analysis['version'];
				$remainingPath = $analysis['remainingPath'];
			}
		}

		if ($apiId && $version && $this->apiRegistry->hasApi($apiId)) {
			$apiConfig = $this->apiRegistry->getApi($apiId);
			if (in_array($version, $apiConfig['versions'], TRUE)) {
				// Redirect to documentation if the base API URL is called
				if ($remainingPath === '/' && $request->getMethod() === 'GET') {
					$redirectPath = rtrim($path, '/') . '/docs/ui';
					if (!str_ends_with($redirectPath, '/docs/ui')) {
						$redirectPath .= '/docs/ui';
					}
					return new RedirectResponse($redirectPath);
				}

				$securityConfig = $this->apiRegistry->getSecurityConfig($apiId, $version);
				$authMode = $securityConfig['authMode'] ?? 'token';

				return $this->router->dispatch($request, $apiId, $version, $remainingPath, $authMode);
			}
		}

		return $this->createErrorResponse(
			'Not Found',
			'The requested API or version does not exist.',
			404,
			$request
		);
	}

	/**
	 * Creates a Problem JSON error response (RFC 7807)
	 *
	 * @param string $title
	 * @param string $detail
	 * @param int $status
	 * @param ServerRequestInterface|null $request
	 * @return ResponseInterface
	 */
	protected function createErrorResponse(
		string $title,
		string $detail,
		int $status,
		?ServerRequestInterface $request = NULL
	): ResponseInterface {
		$legacyMode = $request?->getAttribute('api.legacyMode');
		if ($legacyMode instanceof ApiLegacyMode && $legacyMode->legacyErrorFormat) {
			return new JsonResponse([
				'error' => $title,
				'message' => $detail,
				'code' => $status
			], $status);
		}

		return new JsonResponse([
			'title' => $title,
			'detail' => $detail,
			'status' => $status,
			'type' => 'about:blank'
		], $status, ['Content-Type' => 'application/problem+json']);
	}
}
