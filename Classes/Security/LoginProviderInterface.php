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
		string $tenantId,
		array $activeProviders = []
	): ?AuthContext;
}
