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
 * Attribute for MCP exposure settings on API endpoints.
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class ApiMcp {
	/**
	 * @param bool $exclude If set to TRUE, the endpoint is hidden from MCP tool discovery.
	 * @param string|null $name Optional MCP tool name override.
	 * @param string|null $description Optional MCP tool description override.
	 * @param string|null $notes Optional MCP notes or usage hints.
	 */
	public function __construct(
		public bool $exclude = FALSE,
		public ?string $name = NULL,
		public ?string $description = NULL,
		public ?string $notes = NULL
	) {
	}

	/**
	 * @param array<string, mixed> $properties
	 */
	public static function __set_state(array $properties): self {
		$exclude = $properties['exclude'] ?? FALSE;
		$name = $properties['name'] ?? NULL;
		$description = $properties['description'] ?? NULL;
		$notes = $properties['notes'] ?? NULL;

		return new self(
			\is_bool($exclude) ? $exclude : FALSE,
			\is_string($name) ? $name : NULL,
			\is_string($description) ? $description : NULL,
			\is_string($notes) ? $notes : NULL
		);
	}
}
