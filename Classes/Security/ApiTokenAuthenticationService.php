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
	 */
	public function __construct(
		protected ?JwtService $jwtService = NULL,
		protected ?TokenRepository $tokenRepository = NULL,
		protected ?ConnectionPool $connectionPool = NULL
	) {
		$this->jwtService ??= GeneralUtility::makeInstance(JwtService::class);
		$this->tokenRepository ??= GeneralUtility::makeInstance(TokenRepository::class);
		$this->connectionPool ??= GeneralUtility::makeInstance(ConnectionPool::class);
	}

	/**
	 * @return array|null
	 * @throws Exception
	 * @throws \JsonException
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
		if (count(explode('.', $token)) === 3) {
			$payload = $this->jwtService->decode($token);
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
			->where(
				$queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($userId))
			)
			->executeQuery()
			->fetchAssociative();

		return $user ?: NULL;
	}
}
