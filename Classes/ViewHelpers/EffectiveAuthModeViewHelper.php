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

namespace SGalinski\SgApiCore\ViewHelpers;

use SGalinski\SgApiCore\Service\ApiRegistry;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * ViewHelper to get the effective auth mode for an API
 */
class EffectiveAuthModeViewHelper extends AbstractViewHelper {
	/**
	 * @param ApiRegistry $apiRegistry
	 */
	public function __construct(
		protected readonly ApiRegistry $apiRegistry
	) {
	}

	/**
	 * Initialize arguments
	 */
	public function initializeArguments(): void {
		parent::initializeArguments();
		$this->registerArgument('apiId', 'string', 'The API ID', TRUE);
	}

	/**
	 * Execute the view helper
	 *
	 * @return string
	 */
	public function render(): string {
		$apiId = (string) $this->arguments['apiId'];
		$securityConfig = $this->apiRegistry->getSecurityConfig($apiId, '');
		return (string) ($securityConfig['authMode'] ?? 'token');
	}
}
