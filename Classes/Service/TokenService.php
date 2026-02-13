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

namespace SGalinski\SgApiCore\Service;

use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Domain\Repository\TokenRepository;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Service for managing API tokens
 */
class TokenService implements SingletonInterface {
	public function __construct(
		protected ?TokenRepository $tokenRepository = NULL,
		protected ?JwtService $jwtService = NULL,
		protected ?ExtensionConfiguration $extensionConfiguration = NULL,
		protected ?ConnectionPool $connectionPool = NULL
	) {
		$this->tokenRepository ??= GeneralUtility::makeInstance(TokenRepository::class);
		$this->jwtService ??= GeneralUtility::makeInstance(JwtService::class);
		$this->extensionConfiguration ??= GeneralUtility::makeInstance(ExtensionConfiguration::class);
		$this->connectionPool ??= GeneralUtility::makeInstance(ConnectionPool::class);
	}

	/**
	 * Generates a new random token
	 *
	 * @return string
	 * @throws \Random\RandomException
	 */
	public function generateRandomToken(): string {
		return bin2hex(random_bytes(32));
	}

	/**
	 * Creates a new token record in the database
	 *
	 * @param string $token The plaintext token
	 * @param string $apiId
	 * @param string $tenantId
	 * @param int $pid
	 * @param array $scopes
	 * @param int|null $userId
	 * @param bool $isRefreshToken
	 * @param int|null $expiresAt
	 * @param string $label
	 * @return int The UID of the new token record
	 * @throws \JsonException
	 */
	public function createToken(
		string $token,
		string $apiId,
		string $tenantId,
		int $pid,
		array $scopes = [],
		?int $userId = NULL,
		bool $isRefreshToken = FALSE,
		?int $expiresAt = NULL,
		string $label = ''
	): int {
		$tokenHash = hash('sha256', $token);
		$connection = $this->connectionPool->getConnectionForTable('tx_apicore_token');

		$connection->insert('tx_apicore_token', [
			'pid' => $pid,
			'tenant_id' => $tenantId,
			'api_id' => $apiId,
			'token_hash' => $tokenHash,
			'user_id' => (int) $userId,
			'is_refresh_token' => (int) $isRefreshToken,
			'scopes' => json_encode($scopes, JSON_THROW_ON_ERROR),
			'expires_at' => (int) $expiresAt,
			'label' => $label,
			'crdate' => time(),
			'tstamp' => time()
		]);

		return (int) $connection->lastInsertId();
	}

	/**
	 * Generates a JWT access token for a user
	 *
	 * @param int $userId
	 * @param string $apiId
	 * @param string $tenantId
	 * @param array $scopes
	 * @param int|string $jti Unique ID for the token (usually the UID of the database record or a unique string)
	 * @return string
	 * @throws \JsonException
	 */
	public function generateJwtAccessToken(
		int $userId,
		string $apiId,
		string $tenantId,
		array $scopes,
		int|string $jti
	): string {
		$now = time();
		$ttl = $this->extensionConfiguration->getJwtAccessTokenTtlSeconds();
		$payload = [
			'userId' => $userId,
			'apiId' => $apiId,
			'tenantId' => $tenantId,
			'scopes' => $scopes,
			'iat' => $now,
			'nbf' => $now,
			'exp' => $now + $ttl,
			'jti' => $jti,
			'iss' => 'sg_apicore',
			'aud' => $apiId
		];

		return $this->jwtService->encode($payload);
	}
}
