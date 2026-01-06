<?php

/***************************************************************
 *  Copyright notice
 *
 *  (c) sgalinski Internet Services (https://www.sgalinski.de)
 *
 *  All rights reserved
 *
 *  This script is part of the TYPO3 project. The TYPO3 project is
 *  free software; you can redistribute it and/or modify
 *  it under the terms of the GNU General Public License as published by
 *  the Free Software Foundation; either version 3 of the License, or
 *  (at your option) any later version.
 *
 *  The GNU General Public License can be found at
 *  http://www.gnu.org/copyleft/gpl.html.
 *
 *  This script is distributed in the hope that it will be useful,
 *  but WITHOUT ANY WARRANTY; without even the implied warranty of
 *  MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
 *  GNU General Public License for more details.
 *
 *  This copyright notice MUST APPEAR in all copies of the script!
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
		$request->method('getAttribute')->with('site')->willReturn(NULL);

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

		$request->method('getAttribute')->with('site')->willReturn($site);

		$config = $this->createStub(ExtensionConfiguration::class);
		$config->method('getSiteTenantIdSource')->willReturn('identifier');

		$resolver = new SiteTenantResolver($config);

		$result = $resolver->resolve($request);
		$this->assertTrue($result->isSuccess());
		$this->assertEquals('main-site', $result->getContext()->getTenantId());
		$this->assertEquals('main-site', $result->getContext()->getSiteIdentifier());
	}
}
