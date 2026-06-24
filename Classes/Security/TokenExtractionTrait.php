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

namespace SGalinski\SgApiCore\Security;

use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;

/**
 * Trait for shared token extraction logic
 */
trait TokenExtractionTrait {
	/**
	 * Extracts the token from various possible locations
	 *
	 * @param ServerRequestInterface $request
	 * @return string
	 */
	protected function extractToken(ServerRequestInterface $request): string {
		// 1. Authorization: Bearer <token>
		$authorizationHeader = $request->getHeaderLine('Authorization');

		// Handle cases where Apache strips the Authorization header (common in CGI/FastCGI)
		if ($authorizationHeader === '') {
			$serverParams = $request->getServerParams();
			$authorizationHeader = $serverParams['HTTP_AUTHORIZATION']
				?? $serverParams['REDIRECT_HTTP_AUTHORIZATION']
				?? '';
		}

		$bearerToken = $this->extractBearerTokenFromAuthorizationHeader($authorizationHeader);
		if ($bearerToken !== '') {
			return $bearerToken;
		}

		// 2. Custom 'authtoken' or 'bearertoken' header (common in legacy systems or weird clients)
		if ($request->hasHeader('authtoken')) {
			return $request->getHeaderLine('authtoken');
		}

		if ($request->hasHeader('bearertoken')) {
			return $request->getHeaderLine('bearertoken');
		}

		// 3. Query Parameter 'authtoken' or 'bearertoken'
		$legacySupportEnabled = $this->getExtensionConfiguration()->isActivateLegacySupport();
		if (!$legacySupportEnabled) {
			return '';
		}

		return (string) ($request->getQueryParams()['authtoken'] ?? $request->getQueryParams()['bearertoken'] ?? '');
	}

	/**
	 * Extracts a bearer token from an Authorization header value.
	 *
	 * The auth scheme is case-insensitive per RFC 9110, so clients may send
	 * "Bearer", "bearer", or any other casing.
	 *
	 * @param string $authorizationHeader
	 * @return string
	 */
	protected function extractBearerTokenFromAuthorizationHeader(string $authorizationHeader): string {
		if (!preg_match('/^Bearer\s+(.+)$/i', trim($authorizationHeader), $matches)) {
			return '';
		}

		return trim((string) ($matches[1] ?? ''));
	}

	/**
	 * @return ExtensionConfiguration
	 */
	abstract protected function getExtensionConfiguration(): ExtensionConfiguration;
}
