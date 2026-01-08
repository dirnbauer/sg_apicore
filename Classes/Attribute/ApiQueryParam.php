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
 * Attribute to define an API query parameter
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class ApiQueryParam {
	/**
	 * @param string $name The name of the parameter
	 * @param string $type The data type (e.g., string, integer, boolean)
	 * @param bool $required Whether the parameter is mandatory
	 * @param string|null $description A description of the parameter
	 * @param string|null $pattern Optional regex pattern for validation
	 * @param mixed|null $example An example value for the parameter
	 */
	public function __construct(
		public string $name,
		public string $type = 'string',
		public bool $required = FALSE,
		public ?string $description = NULL,
		public ?string $pattern = NULL,
		public mixed $example = NULL
	) {
	}
}
