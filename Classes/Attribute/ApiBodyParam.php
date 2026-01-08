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
 * Attribute to describe a parameter in the request body (JSON)
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
class ApiBodyParam {
	/**
	 * @param string $name The parameter name
	 * @param string $type The parameter type (e.g., string, integer, boolean)
	 * @param bool $required Whether the parameter is required
	 * @param string $description A short description of the parameter
	 * @param string|null $pattern Optional regex pattern for validation
	 * @param mixed|null $example An example value for the parameter
	 */
	public function __construct(
		public string $name,
		public string $type = 'string',
		public bool $required = TRUE,
		public string $description = '',
		public ?string $pattern = NULL,
		public mixed $example = NULL
	) {
	}
}
