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

use Doctrine\DBAL\Exception;
use Psr\EventDispatcher\EventDispatcherInterface;
use Psr\Http\Message\ServerRequestInterface;
use Random\RandomException;
use SGalinski\SgAccount\AccountConfiguration\ConfigurationFactory;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Context\TenantContext;
use SGalinski\SgApiCore\Domain\Repository\TokenRepository;
use SGalinski\SgApiCore\Event\AfterUserAuthenticationEvent;
use SGalinski\SgApiCore\Security\AuthContext;
use TYPO3\CMS\Core\Crypto\PasswordHashing\InvalidPasswordHashException;
use TYPO3\CMS\Core\Crypto\PasswordHashing\PasswordHashFactory;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Service for user authentication logic
 */
class UserAuthService implements SingletonInterface {
	public function __construct(
		protected ConnectionPool $connectionPool,
		protected PasswordHashFactory $passwordHashFactory,
		protected ApiRegistry $apiRegistry,
		protected TokenService $tokenService,
		protected TokenRepository $tokenRepository,
		protected ExtensionConfiguration $extensionConfiguration,
		protected LogService $logService,
		protected EventDispatcherInterface $eventDispatcher,
		protected ?JwtService $jwtService = NULL
	) {
		$this->jwtService ??= GeneralUtility::makeInstance(JwtService::class);
	}

	/**
	 * Authenticates a user by username and password.
	 * Returns the user record on success, or NULL on failure.
	 *
	 * @param string $username
	 * @param string $password
	 * @param TenantContext|null $tenantContext
	 * @return array|null
	 * @throws Exception
	 * @throws InvalidPasswordHashException
	 */
	public function authenticateUser(string $username, string $password, ?TenantContext $tenantContext): ?array {
		$user = $this->findUserByUsername($username, $tenantContext);
		if (!$user) {
			return NULL;
		}

		$hashInstance = $this->passwordHashFactory->get($user['password'], 'FE');
		if (!$hashInstance->checkPassword($password, $user['password'])) {
			return NULL;
		}

		$event = new AfterUserAuthenticationEvent($user, $tenantContext);
		$this->eventDispatcher->dispatch($event);

		return $user;
	}

	/**
	 * Finds a user in fe_users by username and site context.
	 *
	 * @param string $username
	 * @param TenantContext|null $tenantContext
	 * @return array|null
	 * @throws Exception
	 */
	public function findUserByUsername(string $username, ?TenantContext $tenantContext): ?array {
		$siteRootPageId = $tenantContext?->getSiteRootPageId();
		$storagePids = $this->resolveUserStoragePids($tenantContext);

		$queryBuilder = $this->connectionPool->getQueryBuilderForTable('fe_users');
		$queryBuilder->select('*')
			->from('fe_users')
			->where(
				$queryBuilder->expr()->eq('username', $queryBuilder->createNamedParameter($username)),
				$queryBuilder->expr()->eq('disable', 0),
				$queryBuilder->expr()->eq('deleted', 0)
			);

		if (\count($storagePids) > 0) {
			$queryBuilder->andWhere(
				$queryBuilder->expr()->in('pid', $queryBuilder->createNamedParameter($storagePids, Connection::PARAM_INT_ARRAY))
			);
		}

		return $queryBuilder->executeQuery()->fetchAssociative() ?: NULL;
	}

	/**
	 * Generates a set of access and refresh tokens for a user, resolving scopes from the request.
	 *
	 * @param array $user
	 * @param ServerRequestInterface $request
	 * @param string $apiId
	 * @param string $version
	 * @return array
	 * @throws \JsonException
	 * @throws \Random\RandomException
	 */
	public function generateTokensForUserWithScopeHandling(
		array $user,
		ServerRequestInterface $request,
		string $apiId,
		string $version = '1'
	): array {
		/** @var TenantContext|null $tenantContext */
		$tenantContext = $request->getAttribute('api.tenant');

		// Scope Handling
		$scopes = ['user'];
		/** @var AuthContext|null $authContext */
		$authContext = $request->getAttribute('api.auth');
		if ($authContext instanceof AuthContext) {
			$scopes = array_unique(array_merge($scopes, $authContext->getScopes()));
		} else {
			// Fallback: If the Authorization header contains a valid bearer for this API/tenant, merge its scopes
			$authorization = $request->getHeaderLine('Authorization');
			if (stripos($authorization, 'Bearer ') === 0) {
				$bearer = substr($authorization, 7);
				if ($bearer !== '') {
					$hash = hash('sha256', $bearer);
					$tenantId = $tenantContext?->getTenantId() ?? '';
					$siteRootPageId = $tenantContext?->getSiteRootPageId();
					$tokenRecord = $this->tokenRepository->findByHashApiAndTenant($hash, $apiId, $tenantId, $siteRootPageId, TRUE);
					if ($tokenRecord !== NULL) {
						$expired = ((int) ($tokenRecord['expires_at'] ?? 0) > 0) &&
							((int) $tokenRecord['expires_at'] < time());
						if (!$expired) {
							$inherited = [];
							if (!empty($tokenRecord['scopes'])) {
								try {
									$inherited = json_decode((string) $tokenRecord['scopes'], TRUE, 512, JSON_THROW_ON_ERROR) ?: [];
								} catch (\JsonException) {
									$inherited = [];
								}
							}
							$scopes = array_values(array_unique(array_merge($scopes, $inherited)));
						}
					}
				}
			}
		}

		return $this->generateTokensForUser($user, $apiId, $version, $tenantContext, $scopes);
	}

	/**
	 * Generates a set of access and refresh tokens for a user.
	 *
	 * @param array $user
	 * @param string $apiId
	 * @param string $version
	 * @param TenantContext|null $tenantContext
	 * @param array $scopes
	 * @return array
	 * @throws \JsonException
	 * @throws \Random\RandomException
	 */
	public function generateTokensForUser(
		array $user,
		string $apiId,
		string $version,
		?TenantContext $tenantContext,
		array $scopes = ['user']
	): array {
		$tenantId = $tenantContext?->getTenantId() ?? '';
		$siteRootPageId = $tenantContext?->getSiteRootPageId() ?? 0;

		// Create Refresh Token (Opaque)
		$refreshToken = $this->tokenService->generateRandomToken();
		$refreshTtl = $this->extensionConfiguration->getRefreshTokenTtlSeconds();
		$this->tokenService->createToken(
			$refreshToken,
			$apiId,
			$tenantId,
			(int) $siteRootPageId,
			$scopes,
			(int) $user['uid'],
			TRUE,
			$refreshTtl > 0 ? (time() + $refreshTtl) : 0,
			'Refresh Token for ' . ($user['username'] ?? $user['uid'])
		);

		// Update lastlogin
		$queryBuilder = $this->connectionPool->getQueryBuilderForTable('fe_users');
		$queryBuilder->update('fe_users')
			->set('lastlogin', time())
			->where(
				$queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($user['uid'], Connection::PARAM_INT))
			)
			->executeStatement();

		$this->logService->logInfo(
			'user_login_success',
			['userId' => (int) $user['uid'], 'apiId' => $apiId, 'tenantId' => $tenantId]
		);

		$accessTokenData = $this->generateAccessToken(
			(int) $user['uid'],
			$apiId,
			$version,
			$tenantContext,
			$scopes,
			'login-' . $user['uid'] . '-' . time()
		);

		return [
			'access_token' => $accessTokenData['access_token'],
			'refresh_token' => $refreshToken,
			'token_type' => 'Bearer',
			'expires_in' => $accessTokenData['expires_in'],
		];
	}

	/**
	 * Generates a new access token for a user.
	 *
	 * @param int $userId
	 * @param string $apiId
	 * @param string $version
	 * @param TenantContext|null $tenantContext
	 * @param array $scopes
	 * @param string $jti (for JWT)
	 * @return array ['access_token' => string, 'expires_in' => int]
	 * @throws \JsonException
	 * @throws \Random\RandomException
	 */
	public function generateAccessToken(
		int $userId,
		string $apiId,
		string $version,
		?TenantContext $tenantContext,
		array $scopes,
		string $jti = ''
	): array {
		$tenantId = $tenantContext?->getTenantId() ?? '';
		$siteRootPageId = $tenantContext?->getSiteRootPageId() ?? 0;
		$securityConfig = $this->apiRegistry->getSecurityConfig($apiId, $version);
		$activeProviders = $securityConfig['authProviders'] ?? [];
		$useJwt = \in_array('jwtaccesstokenprovider', array_map('strtolower', $activeProviders), TRUE);

		// Determine TTL dynamically based on the provider
		$expiresIn = $useJwt
			? $this->extensionConfiguration->getJwtAccessTokenTtlSeconds()
			: $this->extensionConfiguration->getOpaqueAccessTokenTtlSeconds();

		if ($useJwt) {
			$jti = $jti ?: 'access-' . $userId . '-' . time();
			$accessToken = $this->tokenService->generateJwtAccessToken($userId, $apiId, $tenantId, $scopes, $jti);

			// Also create a database record for the JWT so it can be revoked via jti
			$this->tokenService->createToken(
				$jti,
				$apiId,
				$tenantId,
				$siteRootPageId,
				$scopes,
				$userId,
				FALSE,
				$expiresIn > 0 ? (time() + $expiresIn) : 0,
				'Access Token (JWT) for user ' . $userId,
				TRUE
			);
		} else {
			$accessToken = $this->tokenService->generateRandomToken();
			$this->tokenService->createToken(
				$accessToken,
				$apiId,
				$tenantId,
				$siteRootPageId,
				$scopes,
				$userId,
				FALSE,
				$expiresIn > 0 ? (time() + $expiresIn) : 0,
				'Access Token for user ' . $userId
			);
		}

		return [
			'access_token' => $accessToken,
			'expires_in' => $expiresIn,
		];
	}

	/**
	 * Refreshes an access token using a refresh token.
	 * Returns a new access token (and potentially other info).
	 *
	 * @param string $refreshToken
	 * @param string $apiId
	 * @param string $version
	 * @param TenantContext|null $tenantContext
	 * @return array
	 * @throws Exception
	 * @throws \JsonException
	 * @throws RandomException
	 */
	public function refreshTokens(
		string $refreshToken,
		string $apiId,
		string $version,
		?TenantContext $tenantContext
	): array {
		$tenantId = $tenantContext?->getTenantId() ?? '';
		$siteRootPageId = $tenantContext?->getSiteRootPageId();

		$refreshTokenHash = hash('sha256', $refreshToken);
		$tokenRecord = $this->tokenRepository->findByHashApiAndTenant(
			$refreshTokenHash,
			$apiId,
			$tenantId,
			$siteRootPageId,
			TRUE
		);

		if ($tokenRecord === NULL || !$tokenRecord['is_refresh_token']) {
			throw new \RuntimeException('Invalid refresh token.', 401);
		}

		// Check expiry
		if ((int) $tokenRecord['expires_at'] > 0 && (int) $tokenRecord['expires_at'] < time()) {
			throw new \RuntimeException('Refresh token expired.', 401);
		}

		$scopes = [];
		if ($tokenRecord['scopes']) {
			$scopes = json_decode($tokenRecord['scopes'], TRUE, 512, JSON_THROW_ON_ERROR);
		}

		$userId = (int) $tokenRecord['user_id'];
		$accessTokenData = $this->generateAccessToken(
			$userId,
			$apiId,
			$version,
			$tenantContext,
			$scopes,
			'refresh-' . $tokenRecord['uid'] . '-' . time()
		);

		// Rotation: revoke the old refresh token and issue a new one
		$this->tokenRepository->revoke((int) $tokenRecord['uid']);

		$newRefreshToken = $this->tokenService->generateRandomToken();
		$refreshTtl = $this->extensionConfiguration->getRefreshTokenTtlSeconds();
		$this->tokenService->createToken(
			$newRefreshToken,
			$apiId,
			$tenantId,
			(int) ($siteRootPageId ?? 0),
			$scopes,
			$userId,
			TRUE,
			$refreshTtl > 0 ? (time() + $refreshTtl) : 0,
			'Refresh Token (rotated) for user ' . $userId
		);

		$this->logService->logInfo(
			'user_refresh_success',
			['userId' => $userId, 'apiId' => $apiId, 'tenantId' => $tenantId]
		);

		return [
			'access_token' => $accessTokenData['access_token'],
			'refresh_token' => $newRefreshToken,
			'token_type' => 'Bearer',
			'expires_in' => $accessTokenData['expires_in'],
		];
	}

	/**
	 * Revokes a user token (access or refresh) and its associated refresh tokens if it's an access token.
	 *
	 * @param string $token
	 * @return void
	 * @throws Exception
	 * @throws \JsonException
	 */
	public function revokeUserToken(string $token): void {
		$tokenHash = hash('sha256', $token);
		$tokenRecord = $this->tokenRepository->findByHashGlobally($tokenHash);

		// If not found by hash, it might be a JWT
		if ($tokenRecord === NULL && \count(explode('.', $token)) === 3) {
			$payload = $this->jwtService->decode($token);
			$jti = $payload['jti'] ?? '';
			if ($jti !== '') {
				$tokenRecord = $this->tokenRepository->findByHashGlobally($jti);
			}
		}

		if ($tokenRecord && (int) ($tokenRecord['user_id'] ?? 0) > 0) {
			$this->tokenRepository->revoke((int) $tokenRecord['uid']);

			// If it's an access token (is_refresh_token = 0), we also want to revoke any refresh tokens for this user,
			// api and tenant to ensure a full logout.
			if (!(int) ($tokenRecord['is_refresh_token'] ?? 0)) {
				$filters = [
					'apiId' => $tokenRecord['api_id'] ?? '',
					'tenantId' => $tokenRecord['tenant_id'] ?? '',
					'isRefreshToken' => 1,
					'isUserToken' => 1,
					'status' => 'active',
				];
				$activeRefreshTokens = $this->tokenRepository->findAllWithFilters($filters);
				foreach ($activeRefreshTokens as $refreshTokenRecord) {
					if ((int) $refreshTokenRecord['user_id'] === (int) $tokenRecord['user_id']) {
						$this->tokenRepository->revoke((int) $refreshTokenRecord['uid']);
					}
				}
			}
		}
	}

	/**
	 * Resolves the storage PIDs for fe_users based on the site context.
	 *
	 * @param TenantContext|null $tenantContext
	 * @return array
	 */
	public function resolveUserStoragePids(?TenantContext $tenantContext): array {
		$siteRootPageId = $tenantContext?->getSiteRootPageId();
		$storagePids = [];
		if ($siteRootPageId > 0) {
			$storagePids[] = (int) $siteRootPageId;
		}

		// EXT:sg_account support
		if (class_exists(ConfigurationFactory::class)) {
			try {
				$accountConfiguration = ConfigurationFactory::getMainConfiguration(0, (int) $siteRootPageId);
				if ($accountConfiguration) {
					$settings = $accountConfiguration->getSettings();
					if (isset($settings['frontendUserStoragePage']) && (int) $settings['frontendUserStoragePage'] > 0) {
						$storagePids = GeneralUtility::intExplode(',', (string) $settings['frontendUserStoragePage'], TRUE);
					}
				}
			} catch (\Throwable) {
				// Fallback to site root if sg_account fails
			}
		}

		// Support Site Configuration overrides
		$site = $tenantContext?->getSite();
		if ($site) {
			$siteConfig = $site->getConfiguration();
			if (isset($siteConfig['apicore']['userStoragePids'])) {
				$storagePids = GeneralUtility::intExplode(',', (string) $siteConfig['apicore']['userStoragePids'], TRUE);
			}
		}

		return $storagePids;
	}
}
