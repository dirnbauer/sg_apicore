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
	 * @param array $activeProviders (only used by the chain)
	 * @return AuthContext|null
	 */
	public function authenticate(
		ServerRequestInterface $request,
		string $apiId,
		?string $tenantId,
		array $activeProviders = []
	): ?AuthContext;
}
