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
use SGalinski\SgApiCore\Service\JwtService;

/**
 * JWT Access Token login provider
 */
class JwtAccessTokenProvider implements LoginProviderInterface {
	use TokenExtractionTrait;

	/**
	 * @param JwtService $jwtService
	 * @param TokenRepository $tokenRepository
	 * @param ExtensionConfiguration $extensionConfiguration
	 */
	public function __construct(
		protected JwtService $jwtService,
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
	 * @param string $tenantId
	 * @param array $activeProviders
	 * @return AuthContext|null
	 * @throws \JsonException
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

		// Check if the jti is revoked in the database
		$jti = $payload['jti'] ?? '';
		if ($jti !== '') {
			$tokenRecord = $this->tokenRepository->findByHashGlobally($jti);
			if ($tokenRecord === NULL) {
				// If we don't find it globally (non-revoked), it's either missing OR revoked.
				// To be sure, we could check if it exists in a revoked state.
				// For now, if it's missing from "active" tokens, we treat it as potentially revoked
				// ONLY IF we know we should have a record for it.
				// Since we just introduced storing JTIs, old tokens won't have a record.
				// But findByHashGlobally only returns tokens where revoked_at = 0.
				// If a token was revoked, findByHashGlobally returns NULL.
				// If a token never existed in DB, findByHashGlobally also returns NULL.

				// Let's do a more specific check: Does it exist and is it revoked?
				$connection = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class)
					?->getConnectionForTable('tx_apicore_token');
				$revokedRecord = $connection->select(
					['uid'],
					'tx_apicore_token',
					['token_hash' => $jti]
				)->fetchAssociative();

				if ($revokedRecord && $this->isTokenRevoked($revokedRecord)) {
					return NULL;
				}
			}
		}

		return new AuthContext(
			apiId: $apiId,
			tenantId: $tenantId,
			tokenUid: $payload['jti'] ?? NULL,
			scopes: $payload['scopes'] ?? [],
			userId: $payload['userId'] ?? NULL
		);
	}

	/**
	 * @param array $tokenRecord
	 * @return bool
	 * @throws Exception
	 */
	protected function isTokenRevoked(array $tokenRecord): bool {
		$connection = \TYPO3\CMS\Core\Utility\GeneralUtility::makeInstance(\TYPO3\CMS\Core\Database\ConnectionPool::class)
			?->getConnectionForTable('tx_apicore_token');
		$record = $connection->select(
			['revoked_at'],
			'tx_apicore_token',
			['uid' => (int) $tokenRecord['uid']]
		)->fetchAssociative();

		return $record && (int) $record['revoked_at'] > 0;
	}
}
