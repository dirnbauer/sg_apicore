<?php

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
