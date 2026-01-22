<?php

/***************************************************************
 *  Copyright notice
 *  (c) sgalinski Internet Services (https://www.sgalinski.de)
 *
 *  All rights reserved
 *
 *  This file is part of the TYPO3 CMS project.
 *  It is free software; you can redistribute it and/or modify it under
 *  the terms of the GNU General Public License, either version 3
 *  of the License, or any later version.
 ***************************************************************/

namespace SGalinski\SgApiCore\Middleware;

use Doctrine\DBAL\Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Service\LogService;
use SGalinski\SgApiCore\Service\RateLimitService;
use SGalinski\SgApiCore\Service\ResponseService;

/**
 * Middleware to enforce API rate limits
 */
class RateLimitMiddleware implements MiddlewareInterface {
	public function __construct(
		protected ExtensionConfiguration $extensionConfiguration,
		protected RateLimitService $rateLimitService,
		protected ResponseService $responseService,
		protected LogService $logService
	) {
	}

	/**
	 * @param ServerRequestInterface $request
	 * @param RequestHandlerInterface $handler
	 * @return ResponseInterface
	 * @throws Exception
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
		if (!$this->extensionConfiguration->isRateLimitEnabled()) {
			return $handler->handle($request);
		}

		$apiId = (string) $request->getAttribute('api.id');
		if ($apiId === '') {
			return $handler->handle($request);
		}

		$limit = $this->extensionConfiguration->getRateLimitDefaultLimit();
		$windowSeconds = $this->extensionConfiguration->getRateLimitWindowSeconds();
		if ($limit <= 0 || $windowSeconds <= 0) {
			return $handler->handle($request);
		}

		$identifier = $this->buildIdentifier($request);
		$result = $this->rateLimitService->consume($identifier, $limit, $windowSeconds);

		if (!$result['allowed']) {
			$this->logService->logError('Rate limit exceeded.', [
				'identifier' => $identifier,
				'limit' => $result['limit'],
				'reset' => $result['reset']
			]);

			return $this->responseService->createErrorResponse(
				'Too Many Requests',
				'Rate limit exceeded.',
				429,
				additionalData: ['rateLimit' => $result]
			);
		}

		$request = $request->withAttribute('api.rateLimit', $result);
		$response = $handler->handle($request);

		return $response
			->withHeader('X-RateLimit-Limit', (string) $result['limit'])
			->withHeader('X-RateLimit-Remaining', (string) $result['remaining'])
			->withHeader('X-RateLimit-Reset', (string) $result['reset']);
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
	 * @return string
	 */
	protected function getClientIp(ServerRequestInterface $request): string {
		$forwardedFor = $request->getHeaderLine('X-Forwarded-For');
		if ($forwardedFor !== '') {
			$parts = array_map('trim', explode(',', $forwardedFor));
			if (count($parts) > 0 && $parts[0] !== '') {
				return $parts[0];
			}
		}

		$serverParams = $request->getServerParams();
		return $serverParams['REMOTE_ADDR'] ?? 'unknown';
	}
}
