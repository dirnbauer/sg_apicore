<?php

namespace SGalinski\SgApiCore\Attribute;

use Attribute;

/**
 * Attribute for defining required scopes for an endpoint
 */
#[Attribute(Attribute::TARGET_METHOD)]
final readonly class RequireScopes {
	/**
	 * @param array $scopes
	 */
	public function __construct(
		public array $scopes
	) {
	}
}
