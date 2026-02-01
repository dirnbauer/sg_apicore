<?php

/***************************************************************
 *  Copyright notice
 *  (c) sgalinski Internet Services (https://www.sgalinski.de)
 *
 *  All rights reserved
 *
 *  This file is part of the TYPO3 CMS project.
 *  It is free software; you can redistribute it and/or modify it under
 *  the terms of the GNU General Public License, either version 3
 *  of the License, or any later version.
 ***************************************************************/

namespace SGalinski\SgApiCore\Service;

use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Context\TenantContext;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\TypoScript\AST\Node\RootNode;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScript;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;

/**
 * Ensures full TypoScript context for API requests
 */
class ApiTypoScriptSetupService {
	public function __construct(
		protected readonly Context $context,
		protected readonly LogService $logService
	) {
	}

	/**
	 * @param ServerRequestInterface $request
	 * @param TenantContext|null $tenantContext
	 * @return ServerRequestInterface
	 */
	public function ensureTypoScript(ServerRequestInterface $request, ?TenantContext $tenantContext): ServerRequestInterface {
		$siteRootPageId = $tenantContext?->getSiteRootPageId() ?? 0;
		if ($siteRootPageId <= 0) {
			return $request;
		}

		$typo3Version = GeneralUtility::makeInstance(Typo3Version::class);
		if ($typo3Version->getMajorVersion() >= 13) {
			$request = $this->initializeTypoScriptV13($request, $siteRootPageId);
		} else {
			$request = $this->initializeTypoScriptV12($request, $siteRootPageId);
		}

		if (isset($GLOBALS['TSFE']) && $GLOBALS['TSFE'] instanceof TypoScriptFrontendController) {
			if ($GLOBALS['TSFE']->id <= 0) {
				$GLOBALS['TSFE']->id = $siteRootPageId;
			}

			try {
				$request = $request->withoutAttribute('frontend.typoscript');
				if (method_exists($GLOBALS['TSFE'], 'getFromCache')) {
					/** @phpstan-ignore-next-line */
					$request = $GLOBALS['TSFE']->getFromCache($request);
				}
				$GLOBALS['TYPO3_REQUEST'] = $request;

				if (class_exists(\TYPO3\CMS\Core\TypoScript\TemplateService::class)
					&& isset($GLOBALS['TSFE']->tmpl)
					&& $GLOBALS['TSFE']->tmpl instanceof \TYPO3\CMS\Core\TypoScript\TemplateService
				) {
					if (isset($GLOBALS['TSFE']->rootLine) && is_array($GLOBALS['TSFE']->rootLine)) {
						/** @phpstan-ignore-next-line */
						$GLOBALS['TSFE']->tmpl->runThroughTemplates($GLOBALS['TSFE']->rootLine);
					}
					/** @phpstan-ignore-next-line */
					$GLOBALS['TSFE']->tmpl->generateConfig();
					/** @phpstan-ignore-next-line */
					$GLOBALS['TSFE']->config = $GLOBALS['TSFE']->tmpl->setup['config.'] ?? [];
				}

				$frontendTypoScript = $request->getAttribute('frontend.typoscript');
				if ($frontendTypoScript instanceof FrontendTypoScript && !$frontendTypoScript->hasSetup()) {
					if (class_exists(\TYPO3\CMS\Core\TypoScript\TemplateService::class)
						&& isset($GLOBALS['TSFE']->tmpl->setup)
						&& is_array($GLOBALS['TSFE']->tmpl->setup)
					) {
						/** @phpstan-ignore-next-line */
						$frontendTypoScript->setSetupArray($GLOBALS['TSFE']->tmpl->setup);
					}
				}
			} catch (\Throwable $e) {
				$this->logService->logException($e, $request);
			}
		}

		return $request;
	}

	/**
	 * @param ServerRequestInterface $request
	 * @param int $siteRootPageId
	 * @return ServerRequestInterface
	 */
	protected function initializeTypoScriptV13(ServerRequestInterface $request, int $siteRootPageId): ServerRequestInterface {
		/** @phpstan-ignore-next-line */
		$pageInformationAttribute = $request->getAttribute('frontend.page.information');
		if ($pageInformationAttribute || isset($GLOBALS['TSFE'])) {
			return $request;
		}

		$pageInformationClass = 'TYPO3\\CMS\\Frontend\\Page\\PageInformation';
		$rootline = [];
		if (class_exists($pageInformationClass)) {
			/** @var \TYPO3\CMS\Frontend\Page\PageInformation $pageInformation */
			$pageInformation = new $pageInformationClass();
			if (method_exists($pageInformation, 'setId')) {
				$pageInformation->setId($siteRootPageId);
			}

			try {
				$rootlineUtility = GeneralUtility::makeInstance(RootlineUtility::class, $siteRootPageId);
				$rootline = $rootlineUtility->get();
			} catch (\Throwable) {
				// Fallback if the rootline cannot be generated
			}
			if (method_exists($pageInformation, 'setRootLine')) {
				$pageInformation->setRootLine($rootline);
			}

			$request = $request->withAttribute('frontend.page.information', $pageInformation);
		} else {
			$pageInformation = NULL;
		}

		$site = $request->getAttribute('site');
		$language = $request->getAttribute('language');
		if (!($site instanceof Site) || !($language instanceof SiteLanguage)) {
			return $request;
		}

		$tsfe = GeneralUtility::makeInstance(
			TypoScriptFrontendController::class,
			$this->context,
			$site,
			$language,
			$pageInformation,
			$request->getAttribute('frontend.user')
		);

		$setupArray = [
			'config.' => [
				'tx_sgapicore.' => [
					'persistence.' => [
						'storagePid' => $siteRootPageId
					]
				]
			]
		];

		$typo3Version = GeneralUtility::makeInstance(Typo3Version::class);
		if ($typo3Version->getMajorVersion() >= 13) {
			/** @phpstan-ignore-next-line */
			$frontendTypoScript = new FrontendTypoScript(new RootNode(), [], [], new RootNode());
		} else {
			/** @phpstan-ignore-next-line */
			$frontendTypoScript = new FrontendTypoScript(new RootNode(), []);
		}

		$frontendTypoScript->setSetupArray($setupArray);
		$request = $request->withAttribute('frontend.typoscript', $frontendTypoScript);
		$tsfe->page = [
			'uid' => $siteRootPageId,
			'starttime' => 0,
			'endtime' => 0,
			'fe_group' => '',
			'tx_staticfilecache_cache_priority' => 0
		];
		$tsfe->id = $siteRootPageId;
		$tsfe->rootLine = $rootline;
		$tsfe->sys_page = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Domain\Repository\PageRepository::class);
		if (class_exists(\TYPO3\CMS\Core\TypoScript\TemplateService::class)) {
			/** @phpstan-ignore-next-line */
			$tsfe->tmpl = GeneralUtility::makeInstance(\TYPO3\CMS\Core\TypoScript\TemplateService::class);
		}
		$GLOBALS['TSFE'] = $tsfe;
		$GLOBALS['TYPO3_REQUEST'] = $request;

		return $request;
	}

	/**
	 * @param ServerRequestInterface $request
	 * @param int $siteRootPageId
	 * @return ServerRequestInterface
	 */
	protected function initializeTypoScriptV12(ServerRequestInterface $request, int $siteRootPageId): ServerRequestInterface {
		if (isset($GLOBALS['TSFE'])) {
			return $request;
		}

		$site = $request->getAttribute('site');
		$language = $request->getAttribute('language');
		if (!$site || !$language) {
			return $request;
		}

		$frontendUser = $request->getAttribute('frontend.user');
		if (!($frontendUser instanceof FrontendUserAuthentication)) {
			$frontendUser = GeneralUtility::makeInstance(FrontendUserAuthentication::class);
		}

		$pageArguments = $request->getAttribute('routing');
		if (!($pageArguments instanceof PageArguments)) {
			$pageArguments = new PageArguments($siteRootPageId, '0', []);
		}

		$tsfe = GeneralUtility::makeInstance(
			TypoScriptFrontendController::class,
			$this->context,
			$site,
			$language,
			$pageArguments,
			$frontendUser
		);
		$tsfe->sys_page = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Domain\Repository\PageRepository::class);

		$rootline = [];
		try {
			$rootlineUtility = GeneralUtility::makeInstance(RootlineUtility::class, $siteRootPageId);
			$rootline = $rootlineUtility->get();
		} catch (\Throwable) {
			// Fallback if the rootline cannot be generated
		}
		$tsfe->rootLine = $rootline;

		if (class_exists(\TYPO3\CMS\Core\TypoScript\TemplateService::class)) {
			/** @phpstan-ignore-next-line */
			$tsfe->tmpl = GeneralUtility::makeInstance(\TYPO3\CMS\Core\TypoScript\TemplateService::class);
		}

		$tsfe->page = [
			'uid' => $siteRootPageId,
			'starttime' => 0,
			'endtime' => 0,
			'fe_group' => '',
			'tx_staticfilecache_cache_priority' => 0
		];
		$tsfe->id = $siteRootPageId;
		$GLOBALS['TSFE'] = $tsfe;
		$GLOBALS['TYPO3_REQUEST'] = $request;

		return $request;
	}
}
