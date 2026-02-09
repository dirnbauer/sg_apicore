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
use SGalinski\SgApiCore\Service\JwtService;

/**
 * JWT Access Token login provider
 */
class JwtAccessTokenProvider implements LoginProviderInterface {
	use TokenExtractionTrait;

	/**
	 * @param JwtService $jwtService
	 * @param ExtensionConfiguration $extensionConfiguration
	 */
	public function __construct(
		protected JwtService $jwtService,
		protected ExtensionConfiguration $extensionConfiguration
	) {
	}

	/**
	 * @return ExtensionConfiguration
	 */
	protected function getExtensionConfiguration(): ExtensionConfiguration {
		return $this->extensionConfiguration;
	}

	/**
	 * Authenticates the request and returns an AuthContext if successful
	 *
	 * @param ServerRequestInterface $request
	 * @param string $apiId
	 * @param string $tenantId
	 * @param array $activeProviders
	 * @return AuthContext|null
	 * @throws \JsonException
	 */
	public function authenticate(
		ServerRequestInterface $request,
		string $apiId,
		?string $tenantId,
		array $activeProviders = []
	): ?AuthContext {
		$tenantId ??= '';
		$token = $this->extractToken($request);
		if ($token === '' || count(explode('.', $token)) !== 3) {
			return NULL;
		}

		$payload = $this->jwtService->decode($token, [
			'tenantId' => $tenantId,
			'apiId' => $apiId
		]);

		if ($payload === NULL) {
			return NULL;
		}

		return new AuthContext(
			apiId: $apiId,
			tenantId: $tenantId,
			tokenUid: $payload['jti'] ?? NULL,
			scopes: $payload['scopes'] ?? [],
			userId: $payload['userId'] ?? NULL
		);
	}
}
