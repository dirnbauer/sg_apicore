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
use Random\RandomException;
use SGalinski\SgApiCore\Attribute\ApiLegacyMode;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Service\LogService;
use SGalinski\SgApiCore\Service\PathAnalysisService;
use SGalinski\SgApiCore\Service\ResponseService;
use SGalinski\SgApiCore\Service\Tenant\TenantResolverInterface;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Context\LanguageAspectFactory;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;

/**
 * Middleware to setup the API request context.
 */
class ApiSetupMiddleware implements MiddlewareInterface {
	protected ExtensionConfiguration $extensionConfiguration;
	protected TenantResolverInterface $tenantResolver;
	protected PathAnalysisService $pathAnalysisService;
	protected LogService $logService;
	protected ResponseService $responseService;
	protected Context $context;

	public function __construct(
		ExtensionConfiguration $extensionConfiguration,
		TenantResolverInterface $tenantResolver,
		PathAnalysisService $pathAnalysisService,
		LogService $logService,
		ResponseService $responseService,
		Context $context
	) {
		$this->extensionConfiguration = $extensionConfiguration;
		$this->tenantResolver = $tenantResolver;
		$this->pathAnalysisService = $pathAnalysisService;
		$this->logService = $logService;
		$this->responseService = $responseService;
		$this->context = $context;
	}

	/**
	 * @param ServerRequestInterface $request
	 * @param RequestHandlerInterface $handler
	 * @return ResponseInterface
	 * @throws RandomException
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
		$uri = $request->getUri();
		$path = $uri->getPath();
		$apiPathPrefix = $this->extensionConfiguration->getApiPathPrefix();

		// Respect TYPO3 Language Prefix
		$language = $request->getAttribute('language');
		$languagePrefix = NULL;
		if ($language instanceof SiteLanguage) {
			$languagePrefix = $language->getBase()->getPath();
		}

		$pathWithoutLanguage = $path;
		if ($languagePrefix !== NULL && $languagePrefix !== '/' && $languagePrefix !== '') {
			$languagePrefix = '/' . trim($languagePrefix, '/') . '/';
			if (str_starts_with($path, $languagePrefix)) {
				$pathWithoutLanguage = '/' . ltrim(substr($path, \strlen($languagePrefix)), '/');
			}
		}

		// Skip if it doesn't start with the API path prefix
		if (!str_starts_with($pathWithoutLanguage, $apiPathPrefix)) {
			return $handler->handle($request);
		}

		// Skip if it contains legacy auth headers and is not already a legacy-mapped request
		// This allows the LegacyRoutingMiddleware to handle these requests if they use the same path prefix
		$hasLegacyAuthHeader = $request->hasHeader('authtoken') || $request->hasHeader('bearertoken');
		if ($hasLegacyAuthHeader && !$request->getAttribute('api.isLegacy')) {
			return $handler->handle($request);
		}

		// Add Request ID
		$requestId = bin2hex(random_bytes(8));
		$request = $request->withAttribute('api.requestId', $requestId);

		// Resolve tenant early
		$tenantResult = $this->tenantResolver->resolve($request);
		if (!$tenantResult->isSuccess()) {
			$statusCode = $this->extensionConfiguration->getOnMissingTenantStatusCode();
			$this->logService->logRejectedRequest($request, 'tenant_resolution_failed', $statusCode, [
				'tenantError' => $tenantResult->getError(),
			]);
			$response = $this->responseService->createErrorResponse(
				'Tenant Resolution Failed',
				'Could not resolve a valid tenant for this request. Reason: ' . $tenantResult->getError(),
				$statusCode
			);
			return $response->withHeader('X-Request-ID', $requestId);
		}
		$request = $request->withAttribute('api.tenant', $tenantResult->getContext());

		// Initialize Language Aspect in Context
		if ($language instanceof SiteLanguage) {
			$languageAspect = LanguageAspectFactory::createFromSiteLanguage($language);
			$this->context->setAspect('language', $languageAspect);

			// Ensure the language aspect is also reflected in the site attribute for some core processes
			$request = $request->withAttribute('language', $language);
		}

		// Analyze Path if not already done by previous middleware (e.g. LegacyRoutingMiddleware)
		if (!$request->getAttribute('api.id') || !$request->getAttribute('api.version')) {
			$analysis = $this->pathAnalysisService->analyze(
				$path,
				$languagePrefix ? $languagePrefix . ltrim($apiPathPrefix, '/') : NULL
			);
			if ($analysis) {
				$request = $request->withAttribute('api.id', $analysis['apiId']);
				$request = $request->withAttribute('api.version', $analysis['version']);
				$request = $request->withAttribute('api.remainingPath', $analysis['remainingPath']);
			}
		}

		$GLOBALS['TYPO3_REQUEST'] = $request;

		// Parse JSON Body if applicable (allowance of text/plain is a legacy fallback)
		$contentType = $request->getHeaderLine('Content-Type');
		if (str_contains($contentType, 'application/json') || str_contains($contentType, 'text/plain')) {
			$body = (string) $request->getBody();
			if ($body !== '') {
				try {
					$parsedBody = json_decode($body, TRUE, 512, JSON_THROW_ON_ERROR);
					if (\is_array($parsedBody)) {
						$request = $request->withParsedBody($parsedBody);
					}
				} catch (\JsonException) {
					// Only throw an error for application/json if it's invalid
					if (str_contains($contentType, 'application/json')) {
						$this->logService->logRejectedRequest($request, 'invalid_json', 400, [
							'contentType' => $contentType,
							'requestBodyLength' => \strlen($body),
							'requestBodyHash' => hash('sha256', $body),
							'_skipRequestBody' => TRUE,
						]);
						$response = $this->responseService->createErrorResponse(
							'Invalid JSON',
							'The request body could not be parsed as JSON. Ensure Content-Type is application/json and the body is valid JSON.',
							400
						);
						return $response->withHeader('X-Request-ID', $requestId);
					}
				}
			}
		}

		$startTime = microtime(TRUE);
		try {
			$response = $handler->handle($request);
		} catch (\Throwable $e) {
			$this->logService->logException($e, $request);
			$status = (int) $e->getCode();
			if ($status < 400 || $status > 599) {
				$status = 500;
			}

			$title = 'Internal Server Error';
			if ($status === 404) {
				$title = 'Not Found';
			} elseif ($status === 403) {
				$title = 'Forbidden';
			} elseif ($status === 401) {
				$title = 'Unauthorized';
			} elseif ($status === 400) {
				$title = 'Bad Request';
			}

			$legacyMode = $request->getAttribute('api.legacyMode');
			if ($legacyMode === NULL && ($request->getAttribute('api.isLegacy') || $request->getAttribute(
				'api.id'
			) === 'legacy')) {
				$legacyMode = new ApiLegacyMode();
			}

			$response = $this->responseService->createErrorResponse(
				$title,
				$e->getMessage(),
				$status,
				legacyMode: $legacyMode
			);
		}
		$duration = microtime(TRUE) - $startTime;
		$this->logService->logRequestResponse($request, $response, $duration);

		return $response->withHeader('X-Request-ID', $requestId);
	}
}
