<?php

namespace SGalinski\SgApiCore\Tests\Unit\Service\Tenant;

use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Service\Tenant\SiteTenantResolver;
use SGalinski\SgApiCore\Service\Tenant\TenantContextResult;
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
