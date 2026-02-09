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
	 * @param string|null $requiredIf Condition for making this field required (e.g. 'field=value')
	 * @param float|null $min Minimum value (for numeric types)
	 * @param float|null $max Maximum value (for numeric types)
	 * @param int|null $minLength Minimum length (for string types)
	 * @param int|null $maxLength Maximum length (for string types)
	 */
	public function __construct(
		public string $name,
		public string $type = 'string',
		public bool $required = FALSE,
		public string $description = '',
		public ?string $pattern = NULL,
		public mixed $example = NULL,
		public ?string $requiredIf = NULL,
		public ?float $min = NULL,
		public ?float $max = NULL,
		public ?int $minLength = NULL,
		public ?int $maxLength = NULL
	) {
	}

	public static function __set_state(array $properties): self {
		return new self(
			$properties['name'] ?? '',
			$properties['type'] ?? 'string',
			$properties['required'] ?? FALSE,
			$properties['description'] ?? '',
			$properties['pattern'] ?? NULL,
			$properties['example'] ?? NULL,
			$properties['requiredIf'] ?? NULL,
			$properties['min'] ?? NULL,
			$properties['max'] ?? NULL,
			$properties['minLength'] ?? NULL,
			$properties['maxLength'] ?? NULL
		);
	}
}
