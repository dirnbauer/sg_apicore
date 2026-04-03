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

namespace SGalinski\SgApiCore\ViewHelpers\Backend;

use TYPO3\CMS\Backend\Routing\Exception\RouteNotFoundException;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3Fluid\Fluid\Core\ViewHelper\AbstractViewHelper;

/**
 * Class EditLinkViewHelper
 **/
class EditLinkViewHelper extends AbstractViewHelper {
	/**
	 * Register the ViewHelpers arguments
	 */
	public function initializeArguments() {
		parent::initializeArguments();
		$this->registerArgument('table', 'string', 'The table of the edit link', TRUE);
		$this->registerArgument('uid', 'int', 'The uid of the record', TRUE);
		$this->registerArgument('new', 'bool', 'Whether the record is new or not', FALSE, FALSE);
		$this->registerArgument('returnUrl', 'string', 'The return URL after editing', FALSE, '');
	}

	/**
	 * Renders the URI for editing a record
	 *
	 * @return string
	 * @throws RouteNotFoundException
	 */
	public function render(): string {
		$parameters = [
			'edit' => [
				$this->arguments['table'] => [
					$this->arguments['uid'] => $this->arguments['new'] ? 'new' : 'edit',
				],
			],
		];

		if ($this->arguments['returnUrl'] !== '') {
			$parameters['returnUrl'] = $this->arguments['returnUrl'];
		}

		$uriBuilder = GeneralUtility::makeInstance(UriBuilder::class);
		return (string) $uriBuilder->buildUriFromRoute('record_edit', $parameters);
	}
}
