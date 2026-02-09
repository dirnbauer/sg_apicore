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
