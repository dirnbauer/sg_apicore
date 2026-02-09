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
 * Attribute to define extra metadata for an API endpoint (primarily for OpenAPI)
 */
#[Attribute(Attribute::TARGET_METHOD | Attribute::TARGET_CLASS)]
final readonly class ApiEndpoint {
	/**
	 * @param string|null $summary A short summary of what the endpoint does
	 * @param string|null $description A longer description of the endpoint
	 * @param array $tags A list of tags for grouping the endpoint (OpenAPI)
	 * @param string|null $requestSchema Reference to a request schema (e.g., DTO class or JSON schema)
	 * @param string|null $responseSchema Reference to a response schema (e.g., DTO class or JSON schema)
	 */
	public function __construct(
		public ?string $summary = NULL,
		public ?string $description = NULL,
		public array $tags = [],
		public ?string $requestSchema = NULL,
		public ?string $responseSchema = NULL
	) {
	}
}
