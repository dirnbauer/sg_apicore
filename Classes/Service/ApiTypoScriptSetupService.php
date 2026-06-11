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

namespace SGalinski\SgApiCore\Service;

use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Context\TenantContext;
use Throwable;
use TYPO3\CMS\Core\Context\Context;
use TYPO3\CMS\Core\Domain\Repository\PageRepository;
use TYPO3\CMS\Core\Information\Typo3Version;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\CMS\Core\Site\Entity\SiteLanguage;
use TYPO3\CMS\Core\TypoScript\AST\Node\RootNode;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScript;
use TYPO3\CMS\Core\TypoScript\TemplateService;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\RootlineUtility;
use TYPO3\CMS\Frontend\Authentication\FrontendUserAuthentication;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;
use TYPO3\CMS\Frontend\Page\PageInformation;

/**
 * Ensures full TypoScript context for API requests
 */
readonly class ApiTypoScriptSetupService {
	public function __construct(
		protected Context $context,
		protected LogService $logService,
		protected Typo3Version $typo3Version
	) {
	}

	/**
	 * @param ServerRequestInterface $request
	 * @param TenantContext|null $tenantContext
	 * @return ServerRequestInterface
	 */
	public function ensureTypoScript(
		ServerRequestInterface $request,
		?TenantContext $tenantContext
	): ServerRequestInterface {
		$siteRootPageId = $tenantContext?->getSiteRootPageId() ?? 0;
		if ($siteRootPageId <= 0) {
			return $request;
		}

		if ($this->typo3Version->getMajorVersion() >= 13) {
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

				if (class_exists(TemplateService::class)
					&& isset($GLOBALS['TSFE']->tmpl)
					&& $GLOBALS['TSFE']->tmpl instanceof TemplateService
				) {
					if (isset($GLOBALS['TSFE']->rootLine) && \is_array($GLOBALS['TSFE']->rootLine)) {
						/** @phpstan-ignore-next-line */
						$GLOBALS['TSFE']->tmpl->runThroughTemplates($GLOBALS['TSFE']->rootLine);
					}
					/** @phpstan-ignore-next-line */
					$GLOBALS['TSFE']->tmpl->generateConfig();
					/** @phpstan-ignore-next-line */
					$GLOBALS['TSFE']->config = $GLOBALS['TSFE']->tmpl->setup['config.'] ?? [];
				}

				$frontendTypoScript = $request->getAttribute('frontend.typoscript');
				if (!($frontendTypoScript instanceof FrontendTypoScript)) {
					$frontendTypoScript = $this->createEmptyFrontendTypoScript();
				}
				if (!$this->hasInitializedSetupArray($frontendTypoScript)) {
					$setupArray = $this->resolveSetupArrayFromTemplateService($siteRootPageId);
					$frontendTypoScript->setSetupArray($setupArray);
					$request = $request->withAttribute('frontend.typoscript', $frontendTypoScript);
					$GLOBALS['TYPO3_REQUEST'] = $request;
				}
			} catch (Throwable $e) {
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
	protected function initializeTypoScriptV13(
		ServerRequestInterface $request,
		int $siteRootPageId
	): ServerRequestInterface {
		/** @phpstan-ignore-next-line */
		$pageInformationAttribute = $request->getAttribute('frontend.page.information');
		if ($pageInformationAttribute || isset($GLOBALS['TSFE'])) {
			return $request;
		}

		$pageInformationClass = 'TYPO3\\CMS\\Frontend\\Page\\PageInformation';
		$rootline = [];
		if (class_exists($pageInformationClass)) {
			/** @var PageInformation $pageInformation */
			$pageInformation = new $pageInformationClass();
			if (method_exists($pageInformation, 'setId')) {
				$pageInformation->setId($siteRootPageId);
			}

			try {
				$rootlineUtility = GeneralUtility::makeInstance(RootlineUtility::class, $siteRootPageId);
				$rootline = $rootlineUtility->get();
			} catch (Throwable) {
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
						'storagePid' => $siteRootPageId,
					],
				],
			],
		];

		if ($this->typo3Version->getMajorVersion() >= 13) {
			/** @phpstan-ignore-next-line */
			$frontendTypoScript = new FrontendTypoScript(new RootNode(), [], [], []);
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
			'tx_staticfilecache_cache_priority' => 0,
		];
		$tsfe->id = $siteRootPageId;
		$tsfe->rootLine = $rootline;
		$tsfe->sys_page = GeneralUtility::makeInstance(PageRepository::class);
		if (class_exists(TemplateService::class)) {
			/** @phpstan-ignore-next-line */
			$tsfe->tmpl = GeneralUtility::makeInstance(TemplateService::class);
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
	protected function initializeTypoScriptV12(
		ServerRequestInterface $request,
		int $siteRootPageId
	): ServerRequestInterface {
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
		$tsfe->sys_page = GeneralUtility::makeInstance(PageRepository::class);

		$rootline = [];
		try {
			$rootlineUtility = GeneralUtility::makeInstance(RootlineUtility::class, $siteRootPageId);
			$rootline = $rootlineUtility->get();
		} catch (Throwable) {
			// Fallback if the rootline cannot be generated
		}
		$tsfe->rootLine = $rootline;

		if (class_exists(TemplateService::class)) {
			/** @phpstan-ignore-next-line */
			$tsfe->tmpl = GeneralUtility::makeInstance(TemplateService::class);
		}

		$tsfe->page = [
			'uid' => $siteRootPageId,
			'starttime' => 0,
			'endtime' => 0,
			'fe_group' => '',
			'tx_staticfilecache_cache_priority' => 0,
		];
		$tsfe->id = $siteRootPageId;
		$GLOBALS['TSFE'] = $tsfe;
		$GLOBALS['TYPO3_REQUEST'] = $request;

		return $request;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function resolveSetupArrayFromTemplateService(int $siteRootPageId): array {
		if (class_exists(TemplateService::class)
			&& isset($GLOBALS['TSFE']->tmpl->setup)
			&& \is_array($GLOBALS['TSFE']->tmpl->setup)
		) {
			/** @var array<string, mixed> $setupArray */
			$setupArray = $GLOBALS['TSFE']->tmpl->setup;
			return $setupArray;
		}

		return [
			'config.' => [
				'tx_sgapicore.' => [
					'persistence.' => [
						'storagePid' => $siteRootPageId,
					],
				],
			],
		];
	}

	private function hasInitializedSetupArray(FrontendTypoScript $frontendTypoScript): bool {
		try {
			$frontendTypoScript->getSetupArray();
			return TRUE;
		} catch (Throwable) {
			return FALSE;
		}
	}

	private function createEmptyFrontendTypoScript(): FrontendTypoScript {
		if ($this->typo3Version->getMajorVersion() >= 13) {
			return new FrontendTypoScript(new RootNode(), [], [], []);
		}

		/** @phpstan-ignore-next-line */
		return new FrontendTypoScript(new RootNode(), []);
	}
}
