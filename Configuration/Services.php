<?php

use SGalinski\SgApiCore\Security\BearerOpaqueTokenProvider;
use SGalinski\SgApiCore\Security\JwtAccessTokenProvider;
use SGalinski\SgApiCore\Security\LoginProviderChain;
use SGalinski\SgApiCore\Security\LoginProviderInterface;
use SGalinski\SgApiCore\Service\Tenant\HeaderTenantResolver;
use SGalinski\SgApiCore\Service\Tenant\SiteTenantResolver;
use SGalinski\SgApiCore\Service\Tenant\TenantResolverChain;
use SGalinski\SgApiCore\Service\Tenant\TenantResolverInterface;
use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;
use function Symfony\Component\DependencyInjection\Loader\Configurator\tagged_iterator;

return static function (ContainerConfigurator $containerConfigurator): void {
	$services = $containerConfigurator->services();
	$services->defaults()
		->private()
		->autowire()
		->autoconfigure();

	$services->load('SGalinski\\SgApiCore\\', __DIR__ . '/../Classes/');

	$services->set(SGalinski\SgApiCore\Service\Router::class)
		->arg('$controllers', tagged_iterator('sg_apicore.router'));

	$services->set(SGalinski\SgApiCore\Service\EndpointDiscoveryService::class)
		->arg('$controllers', tagged_iterator('sg_apicore.router'));

	$services->set(SGalinski\SgApiCore\Service\OpenApiService::class);

	$services->set(SGalinski\SgApiCore\Controller\OpenApiController::class)
		->tag('sg_apicore.router');

	$services->set(SGalinski\SgApiCore\Controller\TestController::class)
		->tag('sg_apicore.router');

	$services->set(SGalinski\SgApiCore\Controller\UserAuthController::class)
		->tag('sg_apicore.router');

	$services->set(SGalinski\SgApiCore\Controller\ResourceController::class)
		->tag('sg_apicore.router');

	$services->set(SGalinski\SgApiCore\Command\GenerateOpenApiCommand::class)
		->tag('console.command', ['command' => 'api:openapi:generate']);

	$services->set(TenantResolverChain::class)
		->arg('$resolvers', tagged_iterator('sg_apicore.tenant_resolver'));

	$services->set(SiteTenantResolver::class)
		->tag('sg_apicore.tenant_resolver');

	$services->set(HeaderTenantResolver::class)
		->tag('sg_apicore.tenant_resolver');

	$services->set(LoginProviderChain::class)
		->arg('$providers', tagged_iterator('sg_apicore.login_provider'));

	$services->set(BearerOpaqueTokenProvider::class)
		->tag('sg_apicore.login_provider');

	$services->set(JwtAccessTokenProvider::class)
		->tag('sg_apicore.login_provider');

	$services->set(SGalinski\SgApiCore\EventListener\ClearCacheEventListener::class)
		->tag('event.listener', ['identifier' => 'sg_apicore_clear_cache']);

	$services->set(SGalinski\SgApiCore\Middleware\ApiSetupMiddleware::class);
	$services->set(SGalinski\SgApiCore\Middleware\ApiAuthMiddleware::class);
	$services->set(SGalinski\SgApiCore\Middleware\ApiCacheMiddleware::class);
	$services->set(SGalinski\SgApiCore\Middleware\ApiRequestMiddleware::class);

	$services->alias(TenantResolverInterface::class, TenantResolverChain::class);
	$services->alias(LoginProviderInterface::class, LoginProviderChain::class);
};
