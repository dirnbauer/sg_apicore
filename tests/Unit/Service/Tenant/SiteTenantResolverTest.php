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

namespace SGalinski\SgApiCore\Tests\Unit\Service\Tenant;

use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Service\Tenant\SiteTenantResolver;
use TYPO3\CMS\Core\Http\Uri;
use TYPO3\CMS\Core\Site\Entity\Site;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Test case for SiteTenantResolver
 */
class SiteTenantResolverTest extends UnitTestCase {
	public function testResolveReturnsFailureWhenSiteMissing(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getAttribute')->willReturnMap([
			['site', NULL, NULL],
			['language', NULL, NULL],
		]);

		$config = $this->createStub(ExtensionConfiguration::class);
		$resolver = new SiteTenantResolver($config);

		$result = $resolver->resolve($request);
		$this->assertFalse($result->isSuccess());
		$this->assertEquals('site_not_found', $result->getError());
	}

	public function testResolveReturnsSuccessWhenSitePresent(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$site = $this->createStub(Site::class);
		$site->method('getIdentifier')->willReturn('main-site');
		$site->method('getBase')->willReturn(new Uri('https://example.org/'));

		$request->method('getAttribute')->willReturnMap([
			['site', NULL, $site],
			['language', NULL, NULL],
		]);

		$config = $this->createStub(ExtensionConfiguration::class);
		$config->method('getSiteTenantIdSource')->willReturn('identifier');

		$resolver = new SiteTenantResolver($config);

		$result = $resolver->resolve($request);
		$this->assertTrue($result->isSuccess());
		$this->assertEquals('main-site', $result->getContext()->getTenantId());
		$this->assertEquals('main-site', $result->getContext()->getSiteIdentifier());
	}

	public function testResolveUsesBaseHostAsTenantId(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$site = $this->createStub(Site::class);
		$site->method('getIdentifier')->willReturn('main-site');
		$site->method('getBase')->willReturn(new Uri('https://tenant-a.org/'));

		$request->method('getAttribute')->willReturnMap([
			['site', NULL, $site],
			['language', NULL, NULL],
		]);

		$config = $this->createStub(ExtensionConfiguration::class);
		$config->method('getSiteTenantIdSource')->willReturn('baseHost');

		$resolver = new SiteTenantResolver($config);

		$result = $resolver->resolve($request);
		$this->assertTrue($result->isSuccess());
		$this->assertEquals('tenant-a.org', $result->getContext()->getTenantId());
	}

	public function testResolveUsesRootPageIdAsTenantId(): void {
		$request = $this->createStub(ServerRequestInterface::class);
		$site = $this->createStub(Site::class);
		$site->method('getIdentifier')->willReturn('main-site');
		$site->method('getRootPageId')->willReturn(123);
		$site->method('getBase')->willReturn(new Uri('https://example.org/'));

		$request->method('getAttribute')->willReturnMap([
			['site', NULL, $site],
			['language', NULL, NULL],
		]);

		$config = $this->createStub(ExtensionConfiguration::class);
		$config->method('getSiteTenantIdSource')->willReturn('rootPageId');

		$resolver = new SiteTenantResolver($config);

		$result = $resolver->resolve($request);
		$this->assertTrue($result->isSuccess());
		$this->assertEquals('123', $result->getContext()->getTenantId());
	}
}
