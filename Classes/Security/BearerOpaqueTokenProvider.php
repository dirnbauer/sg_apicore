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

use Doctrine\DBAL\Exception;
use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Domain\Repository\TokenRepository;

/**
 * Bearer Token login provider
 */
class BearerOpaqueTokenProvider implements LoginProviderInterface {
	use TokenExtractionTrait;

	/**
	 * @param TokenRepository $tokenRepository
	 * @param ExtensionConfiguration $extensionConfiguration
	 */
	public function __construct(
		protected TokenRepository $tokenRepository,
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
	 * @param string|null $tenantId
	 * @param array $activeProviders
	 * @return AuthContext|null
	 * @throws Exception
	 */
	public function authenticate(
		ServerRequestInterface $request,
		string $apiId,
		?string $tenantId,
		array $activeProviders = []
	): ?AuthContext {
		$tenantId ??= '';
		$token = $this->extractToken($request);
		if ($token === '') {
			return NULL;
		}

		/** @var \SGalinski\SgApiCore\Context\TenantContext|null $tenantContext */
		$tenantContext = $request->getAttribute('api.tenant');
		$siteRootPageId = $tenantContext?->getSiteRootPageId();

		$tokenRecord = $this->findMatchingToken($token, $apiId, $tenantId, $siteRootPageId);
		if ($tokenRecord === NULL) {
			return NULL;
		}

		// Check expiry
		if ((int) $tokenRecord['expires_at'] > 0 && (int) $tokenRecord['expires_at'] < time()) {
			return NULL;
		}

		// Update last used
		$this->tokenRepository->updateLastUsed((int) $tokenRecord['uid']);

		$scopes = [];
		if ($tokenRecord['scopes']) {
			try {
				$scopes = json_decode($tokenRecord['scopes'], TRUE, 512, JSON_THROW_ON_ERROR);
			} catch (\JsonException) {
				$scopes = [];
			}

			if (!is_array($scopes)) {
				$scopes = [];
			}
		}

		return new AuthContext(
			apiId: $apiId,
			tenantId: $tenantId,
			tokenUid: (int) $tokenRecord['uid'],
			scopes: $scopes,
			userId: (int) ($tokenRecord['user_id'] ?? 0) ?: NULL
		);
	}

	/**
	 * Finds a matching token record.
	 *
	 * @param string $token
	 * @param string $apiId
	 * @param string $tenantId
	 * @param int|null $siteRootPageId
	 * @return array|null
	 * @throws Exception
	 */
	protected function findMatchingToken(
		string $token,
		string $apiId,
		string $tenantId,
		?int $siteRootPageId = NULL
	): ?array {
		$tokenHash = hash('sha256', $token);
		return $this->tokenRepository->findByHashApiAndTenant($tokenHash, $apiId, $tenantId, $siteRootPageId);
	}
}
