<?php

use SGalinski\SgApiCore\Service\Tenant\HeaderTenantResolver;
use SGalinski\SgApiCore\Service\Tenant\SiteTenantResolver;
use SGalinski\SgApiCore\Service\Tenant\TenantResolverChain;
use SGalinski\SgApiCore\Service\Tenant\TenantResolverInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
	$services = $containerConfigurator->services();
	$services->defaults()
		->private()
		->autowire()
		->autoconfigure();

	$services->load('SGalinski\\SgApiCore\\', __DIR__ . '/../Classes/');

	$services->set(TenantResolverChain::class)
		->arg('$resolvers', [
			Configurator\service(SiteTenantResolver::class),
			Configurator\service(HeaderTenantResolver::class),
		]);

	$services->alias(TenantResolverInterface::class, TenantResolverChain::class);
};
