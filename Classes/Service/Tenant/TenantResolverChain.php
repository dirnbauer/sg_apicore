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

namespace SGalinski\SgApiCore\Service\Tenant;

use Psr\Http\Message\ServerRequestInterface;

/**
 * Chain of tenant resolvers
 */
class TenantResolverChain implements TenantResolverInterface {
	/**
	 * @var TenantResolverInterface[]
	 */
	protected array $resolvers = [];

	/**
	 * @param iterable $resolvers
	 */
	public function __construct(iterable $resolvers) {
		foreach ($resolvers as $resolver) {
			$this->resolvers[] = $resolver;
		}
	}

	/**
	 * @param ServerRequestInterface $request
	 * @return TenantContextResult
	 */
	public function resolve(ServerRequestInterface $request): TenantContextResult {
		$lastError = 'no_resolver_available';
		foreach ($this->resolvers as $resolver) {
			$result = $resolver->resolve($request);
			if ($result->isSuccess()) {
				return $result;
			}
			$lastError = $result->getError();
		}

		return TenantContextResult::failure($lastError);
	}
}
