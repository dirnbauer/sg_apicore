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
use Symfony\Component\DependencyInjection\Attribute\Autowire;
use TYPO3\CMS\Core\Cache\Frontend\PhpFrontend;
use TYPO3\CMS\Core\Routing\PageArguments;
use TYPO3\CMS\Core\Site\Entity\SiteInterface;
use TYPO3\CMS\Core\TypoScript\AST\Node\RootNode;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScript;
use TYPO3\CMS\Core\TypoScript\FrontendTypoScriptFactory;
use TYPO3\CMS\Frontend\Page\PageInformation;
use TYPO3\CMS\Frontend\Page\PageInformationFactory;

/**
 * Ensures full TypoScript context for API requests
 */
class ApiTypoScriptSetupService {
	public function __construct(
		protected readonly LogService $logService,
		protected readonly FrontendTypoScriptFactory $frontendTypoScriptFactory,
		#[Autowire(service: 'cache.typoscript')]
		protected readonly PhpFrontend $typoScriptCache,
		protected readonly PageInformationFactory $pageInformationFactory
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

		try {
			$request = $this->initializePageInformation($request, $siteRootPageId);
			$site = $request->getAttribute('site');
			$pageInformation = $request->getAttribute('frontend.page.information');
			if (!($site instanceof SiteInterface) || !($pageInformation instanceof PageInformation)) {
				return $this->withFallbackTypoScript($request, $siteRootPageId);
			}

			$frontendTypoScript = $request->getAttribute('frontend.typoscript');
			if (!($frontendTypoScript instanceof FrontendTypoScript)) {
				$frontendTypoScript = $this->frontendTypoScriptFactory->createSettingsAndSetupConditions(
					$site,
					$pageInformation->getSysTemplateRows(),
					$this->createConditionMatcherVariables($request, $pageInformation),
					$this->typoScriptCache
				);
			}

			if (!$this->hasInitializedSetupArray($frontendTypoScript)) {
				$frontendTypoScript = $this->frontendTypoScriptFactory->createSetupConfigOrFullSetup(
					TRUE,
					$frontendTypoScript,
					$site,
					$pageInformation->getSysTemplateRows(),
					$this->createConditionMatcherVariables($request, $pageInformation),
					'0',
					$this->typoScriptCache,
					$request
				);
			}

			$request = $request->withAttribute('frontend.typoscript', $frontendTypoScript);
			$GLOBALS['TYPO3_REQUEST'] = $request;
		} catch (\Throwable $e) {
			$this->logService->logException($e, $request);
			$request = $this->withFallbackTypoScript($request, $siteRootPageId);
		}

		return $request;
	}

	private function initializePageInformation(ServerRequestInterface $request, int $siteRootPageId): ServerRequestInterface {
		if ($request->getAttribute('frontend.page.information') instanceof PageInformation) {
			return $request;
		}
		if (!($request->getAttribute('routing') instanceof PageArguments)) {
			$request = $request->withAttribute(
				'routing',
				new PageArguments($siteRootPageId, '0', ['id' => (string) $siteRootPageId])
			);
		}

		return $request->withAttribute('frontend.page.information', $this->pageInformationFactory->create($request));
	}

	/**
	 * @return array<string, mixed>
	 */
	private function createConditionMatcherVariables(
		ServerRequestInterface $request,
		PageInformation $pageInformation
	): array {
		$fullRootLine = $pageInformation->getRootLine();
		ksort($fullRootLine);

		return [
			'request' => $request,
			'pageId' => $pageInformation->getId(),
			'page' => $pageInformation->getPageRecord(),
			'fullRootLine' => $fullRootLine,
			'localRootLine' => $pageInformation->getLocalRootLine(),
			'site' => $request->getAttribute('site'),
			'siteLanguage' => $request->getAttribute('language'),
		];
	}

	private function hasInitializedSetupArray(FrontendTypoScript $frontendTypoScript): bool {
		try {
			$frontendTypoScript->getSetupArray();
			return TRUE;
		} catch (\Throwable) {
			return FALSE;
		}
	}

	private function withFallbackTypoScript(ServerRequestInterface $request, int $siteRootPageId): ServerRequestInterface {
		$frontendTypoScript = new FrontendTypoScript(new RootNode(), [], [], []);
		$frontendTypoScript->setSetupArray([
			'config.' => [
				'tx_sgapicore.' => [
					'persistence.' => [
						'storagePid' => $siteRootPageId,
					],
				],
			],
		]);

		$request = $request->withAttribute('frontend.typoscript', $frontendTypoScript);
		$GLOBALS['TYPO3_REQUEST'] = $request;
		return $request;
	}
}
