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
	 * @var TokenRepository
	 */
	protected TokenRepository $tokenRepository;

	/**
	 * @param TokenRepository $tokenRepository
	 * @param ExtensionConfiguration $extensionConfiguration
	 */
	public function __construct(TokenRepository $tokenRepository, ExtensionConfiguration $extensionConfiguration) {
		$this->tokenRepository = $tokenRepository;
		$this->extensionConfiguration = $extensionConfiguration;
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
