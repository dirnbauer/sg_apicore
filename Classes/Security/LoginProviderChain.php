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

use Psr\Http\Message\ServerRequestInterface;

/**
 * Chain of login providers
 */
class LoginProviderChain implements LoginProviderInterface {
	/**
	 * @var LoginProviderInterface[]
	 */
	protected array $providers;

	/**
	 * @param LoginProviderInterface[] $providers
	 */
	public function __construct(array $providers) {
		$this->providers = $providers;
	}

	/**
	 * @param ServerRequestInterface $request
	 * @param string $apiId
	 * @param string $tenantId
	 * @return AuthContext|null
	 */
	public function authenticate(ServerRequestInterface $request, string $apiId, string $tenantId): ?AuthContext {
		foreach ($this->providers as $provider) {
			$authContext = $provider->authenticate($request, $apiId, $tenantId);
			if ($authContext !== NULL) {
				return $authContext;
			}
		}

		return NULL;
	}
}
