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
 * Interface for Tenant Resolvers
 */
interface TenantResolverInterface {
	/**
	 * Resolves the tenant context from the request
	 *
	 * @param ServerRequestInterface $request
	 * @return TenantContextResult
	 */
	public function resolve(ServerRequestInterface $request): TenantContextResult;
}
