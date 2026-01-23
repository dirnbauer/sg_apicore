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
 * Attribute to define an API response
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::IS_REPEATABLE)]
final readonly class ApiResponse {
	/**
	 * @param int $status The HTTP status code (e.g., 200, 201, 400, 404)
	 * @param string|null $description A description of the response
	 * @param string|null $schema Reference to a response schema (e.g., DTO class or JSON schema)
	 * @param mixed|null $example An example response body
	 */
	public function __construct(
		public int $status = 200,
		public ?string $description = NULL,
		public ?string $schema = NULL,
		public mixed $example = NULL
	) {
	}

	public static function __set_state(array $properties): self {
		return new self(
			$properties['status'] ?? 200,
			$properties['description'] ?? NULL,
			$properties['schema'] ?? NULL,
			$properties['example'] ?? NULL
		);
	}
}
