<?php

namespace SGalinski\SgApiCore\Security;

use Doctrine\DBAL\Exception;
use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Domain\Repository\TokenRepository;
use SGalinski\SgApiCore\Service\JwtService;

/**
 * Bearer Token login provider
 */
class BearerTokenProvider implements LoginProviderInterface {
	/**
	 * @var TokenRepository
	 */
	protected TokenRepository $tokenRepository;

	/**
	 * @var JwtService
	 */
	protected JwtService $jwtService;

	/**
	 * @param TokenRepository $tokenRepository
	 * @param JwtService $jwtService
	 */
	public function __construct(TokenRepository $tokenRepository, JwtService $jwtService) {
		$this->tokenRepository = $tokenRepository;
		$this->jwtService = $jwtService;
	}

	/**
	 * Authenticates the request and returns an AuthContext if successful
	 *
	 * @param ServerRequestInterface $request
	 * @param string $apiId
	 * @param string $tenantId
	 * @return AuthContext|null
	 * @throws Exception
	 * @throws \JsonException
	 */
	public function authenticate(ServerRequestInterface $request, string $apiId, string $tenantId): ?AuthContext {
		$authorizationHeader = $request->getHeaderLine('Authorization');
		if (!str_starts_with($authorizationHeader, 'Bearer ')) {
			return NULL;
		}

		$token = substr($authorizationHeader, 7);
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
			$scopes = json_decode($tokenRecord['scopes'], TRUE, 512, JSON_THROW_ON_ERROR);
			if (!is_array($scopes)) {
				$scopes = [];
			}
		}

		return new AuthContext(
			apiId: $apiId,
			tenantId: $tenantId,
			tokenUid: (int) $tokenRecord['uid'],
			scopes: $scopes
		);
	}

	/**
	 * Finds a matching token record.
	 * Supports both JWT and plaintext token comparison.
	 *
	 * @param string $token
	 * @param string $apiId
	 * @param string $tenantId
	 * @param int|null $siteRootPageId
	 * @return array|null
	 * @throws Exception
	 * @throws \JsonException
	 */
	protected function findMatchingToken(
		string $token,
		string $apiId,
		string $tenantId,
		?int $siteRootPageId = NULL
	): ?array {
		$isJwt = count(explode('.', $token)) === 3;
		if ($isJwt) {
			$payload = $this->jwtService->decode($token);
			if ($payload === NULL) {
				return NULL;
			}
			// In JWT mode, we can look up by a unique identifier from payload if present,
			// or we just look up by the whole token string if it's stored that way.
			// Let's assume we store the JWT in the 'token' field if it's a static JWT.
		}

		$tokenRecords = $this->tokenRepository->findByApiAndTenant($apiId, $tenantId, $siteRootPageId);
		foreach ($tokenRecords as $record) {
			if (hash_equals((string) $record['token'], $token)) {
				return $record;
			}
		}

		return NULL;
	}
}
