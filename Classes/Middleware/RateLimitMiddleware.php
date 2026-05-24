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

use Doctrine\DBAL\Exception;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use SGalinski\SgApiCore\Service\EndpointExecutionGuardService;

/**
 * Middleware to enforce API rate limits.
 */
class RateLimitMiddleware implements MiddlewareInterface {
	public function __construct(
		protected EndpointExecutionGuardService $endpointExecutionGuardService
	) {
	}

	/**
	 * @param ServerRequestInterface $request
	 * @param RequestHandlerInterface $handler
	 * @return ResponseInterface
	 * @throws Exception
	 */
	public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface {
		$guardResult = $this->endpointExecutionGuardService->enforceRateLimit($request);
		if ($guardResult['response'] instanceof ResponseInterface) {
			return $guardResult['response'];
		}

		$response = $handler->handle($guardResult['request']);
		return $this->endpointExecutionGuardService->applyRateLimitHeaders($response, $guardResult['rateLimit']);
	}
}
