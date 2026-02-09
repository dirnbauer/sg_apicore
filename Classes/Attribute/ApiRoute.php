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

namespace SGalinski\SgApiCore\Attribute;

use Attribute;

/**
 * Attribute to define an API route on a controller method
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class ApiRoute {
	/**
	 * @param string $path The route path (e.g., /health)
	 * @param array $methods The allowed HTTP methods (e.g., ['GET'])
	 * @param string|array|null $apiId Optional API ID restriction (string or array of strings)
	 * @param string|array|null $version Optional version restriction (string or array of strings)
	 * @param string|array|null $authMode Optional auth mode restriction (e.g., 'user', 'token', 'public')
	 */
	public function __construct(
		public string $path,
		public array $methods = ['GET'],
		public string|array|NULL $apiId = NULL,
		public string|array|NULL $version = NULL,
		public string|array|NULL $authMode = NULL
	) {
	}
}
