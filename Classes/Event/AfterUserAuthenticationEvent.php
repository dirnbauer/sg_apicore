<?php

namespace SGalinski\SgApiCore\Event;

use SGalinski\SgApiCore\Context\TenantContext;

/**
 * Event triggered after successful user authentication (password check passed).
 * Allows listeners to perform additional validation and block the login by throwing an exception.
 */
class AfterUserAuthenticationEvent {
	/**
	 * @param array $user The user record from fe_users
	 * @param TenantContext|null $tenantContext
	 */
	public function __construct(
		protected array $user,
		protected ?TenantContext $tenantContext = NULL
	) {
	}

	/**
	 * @return array
	 */
	public function getUser(): array {
		return $this->user;
	}

	/**
	 * @return TenantContext|null
	 */
	public function getTenantContext(): ?TenantContext {
		return $this->tenantContext;
	}
}
