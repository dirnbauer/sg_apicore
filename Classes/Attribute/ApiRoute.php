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
