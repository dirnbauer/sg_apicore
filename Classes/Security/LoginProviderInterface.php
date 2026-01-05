<?php

namespace SGalinski\SgApiCore\Security;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Interface for login providers
 */
interface LoginProviderInterface {
	/**
	 * Authenticates the request and returns an AuthContext if successful
	 *
	 * @param ServerRequestInterface $request
	 * @param string $apiId
	 * @param string $tenantId
	 * @return AuthContext|null
	 */
	public function authenticate(ServerRequestInterface $request, string $apiId, string $tenantId): ?AuthContext;
}
