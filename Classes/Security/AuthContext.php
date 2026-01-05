<?php

namespace SGalinski\SgApiCore\Security;

/**
 * Value object for authentication context
 */
final readonly class AuthContext {
	/**
	 * @param string $apiId
	 * @param string $tenantId
	 * @param int|string|null $tokenUid
	 * @param array $scopes
	 */
	public function __construct(
		public string $apiId,
		public string $tenantId,
		public int|string|NULL $tokenUid = NULL,
		public array $scopes = []
	) {
	}

	/**
	 * @return string
	 */
	public function getApiId(): string {
		return $this->apiId;
	}

	/**
	 * @return string
	 */
	public function getTenantId(): string {
		return $this->tenantId;
	}

	/**
	 * @return int|string|null
	 */
	public function getTokenUid(): int|string|NULL {
		return $this->tokenUid;
	}

	/**
	 * @return array
	 */
	public function getScopes(): array {
		return $this->scopes;
	}

	/**
	 * Checks if the context has a specific scope
	 *
	 * @param string $scope
	 * @return bool
	 */
	public function hasScope(string $scope): bool {
		return in_array($scope, $this->scopes, TRUE);
	}
}
