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
use JsonException;
use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Domain\Repository\TokenRepository;
use SGalinski\SgApiCore\Service\JwtService;
use TYPO3\CMS\Core\Authentication\AbstractAuthenticationService;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Authentication service for API tokens
 */
class ApiTokenAuthenticationService extends AbstractAuthenticationService {
	use TokenExtractionTrait;

	/**
	 * ApiTokenAuthenticationService constructor.
	 *
	 * @param JwtService|null $jwtService
	 * @param TokenRepository|null $tokenRepository
	 * @param ConnectionPool|null $connectionPool
	 * @param ExtensionConfiguration|null $extensionConfiguration
	 */
	public function __construct(
		protected ?JwtService $jwtService = NULL,
		protected ?TokenRepository $tokenRepository = NULL,
		protected ?ConnectionPool $connectionPool = NULL,
		protected ?ExtensionConfiguration $extensionConfiguration = NULL
	) {
		$this->jwtService ??= GeneralUtility::makeInstance(JwtService::class);
		$this->tokenRepository ??= GeneralUtility::makeInstance(TokenRepository::class);
		$this->connectionPool ??= GeneralUtility::makeInstance(ConnectionPool::class);
		$this->extensionConfiguration ??= GeneralUtility::makeInstance(ExtensionConfiguration::class);
	}

	/**
	 * @return array|null
	 * @throws Exception
	 * @throws JsonException
	 */
	public function getUser(): ?array {
		if (method_exists($this->pObj, 'setDontSetCookie')) {
			$this->pObj->setDontSetCookie();
		}

		/** @var ServerRequestInterface|null $request */
		$request = $GLOBALS['TYPO3_REQUEST'] ?? NULL;
		if (!$request) {
			return NULL;
		}

		$token = $this->extractToken($request);
		if ($token === '') {
			return NULL;
		}

		// 1. Check JWT
		if (\count(explode('.', $token)) === 3) {
			try {
				$payload = $this->jwtService->decode($token);
			} catch (JsonException) {
				$payload = NULL;
			}
			if ($payload && isset($payload['userId'])) {
				return $this->fetchUserRecordById((int) $payload['userId']);
			}
		}

		// 2. Check Opaque Tokens
		$tokenHash = hash('sha256', $token);
		$tokenRecord = $this->tokenRepository->findByHashGlobally($tokenHash);
		if ($tokenRecord && isset($tokenRecord['user_id']) && (int) $tokenRecord['user_id'] > 0) {
			// Check expiry
			if ((int) $tokenRecord['expires_at'] > 0 && (int) $tokenRecord['expires_at'] < time()) {
				return NULL;
			}
			return $this->fetchUserRecordById((int) $tokenRecord['user_id']);
		}

		return NULL;
	}

	/**
	 * Authenticates the user.
	 * Since we identified the user via a valid token, we return 200 (success).
	 *
	 * @param array $user
	 * @return int
	 */
	public function authUser(array $user): int {
		if (method_exists($this->pObj, 'setDontSetCookie')) {
			$this->pObj->setDontSetCookie();
		}

		return 200;
	}

	/**
	 * @return ExtensionConfiguration
	 */
	protected function getExtensionConfiguration(): ExtensionConfiguration {
		return $this->extensionConfiguration;
	}

	/**
	 * Fetches the user record from the database by UID
	 *
	 * @param int $userId
	 * @return array|null
	 * @throws Exception
	 */
	protected function fetchUserRecordById(int $userId): ?array {
		if ($userId <= 0) {
			return NULL;
		}

		$queryBuilder = $this->connectionPool->getQueryBuilderForTable('fe_users');
		$user = $queryBuilder->select('*')
			->from('fe_users')
			->where($queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($userId)))
			->executeQuery()
			->fetchAssociative();

		return $user ?: NULL;
	}
}
