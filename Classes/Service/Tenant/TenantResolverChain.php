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
