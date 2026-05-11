<?php

use SGalinski\SgApiCore\Service\Router;
use SGalinski\SgApiCore\Service\EndpointDiscoveryService;
use SGalinski\SgApiCore\Service\OpenApiService;
use SGalinski\SgApiCore\Controller\OpenApiController;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Controller\TestController;
use SGalinski\SgApiCore\Controller\LegacyExampleController;
use SGalinski\SgApiCore\Controller\UserAuthController;
use SGalinski\SgApiCore\Controller\McpController;
use SGalinski\SgApiCore\Controller\ResourceController;
use SGalinski\SgApiCore\Command\GenerateOpenApiCommand;
use SGalinski\SgApiCore\Command\McpListCommand;
use SGalinski\SgApiCore\Security\BackendUserProvider;
use SGalinski\SgApiCore\EventListener\ClearCacheEventListener;
use SGalinski\SgApiCore\Middleware\ApiSetupMiddleware;
use SGalinski\SgApiCore\Middleware\ApiCorsMiddleware;
use SGalinski\SgApiCore\Middleware\ApiAuthMiddleware;
use SGalinski\SgApiCore\Middleware\ApiCacheMiddleware;
use SGalinski\SgApiCore\Middleware\ApiRequestMiddleware;
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

	$services->set(Router::class)
		->arg('$controllers', tagged_iterator('sg_apicore.router'));

	$services->set(EndpointDiscoveryService::class)
		->arg('$controllers', tagged_iterator('sg_apicore.router'));

	$services->set(OpenApiService::class);

	$services->set(OpenApiController::class)
		->tag('sg_apicore.router');

	$extensionConfiguration = GeneralUtility::makeInstance(
		ExtensionConfiguration::class
	);
	if ($extensionConfiguration->isActivateDemoApis()) {
		$services->set(TestController::class)
			->tag('sg_apicore.router');

		$services->set(LegacyExampleController::class)
			->tag('sg_apicore.router');
	}

	$services->set(UserAuthController::class)
		->tag('sg_apicore.router');
	$services->set(McpController::class)
		->tag('sg_apicore.router');

	$services->set(ResourceController::class)
		->tag('sg_apicore.router');

	$services->set(GenerateOpenApiCommand::class)
		->tag(
			'console.command',
			[
				'command' => 'api:openapi:generate',
				'description' => GenerateOpenApiCommand::COMMAND_DESCRIPTION,
			]
		);
	$services->set(McpListCommand::class)
		->tag('console.command', ['command' => 'api:mcp:list']);

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

	$services->set(BackendUserProvider::class)
		->tag('sg_apicore.login_provider');

	$services->set(ClearCacheEventListener::class)
		->tag('event.listener', ['identifier' => 'sg_apicore_clear_cache']);

	$services->set(ApiSetupMiddleware::class);
	$services->set(ApiCorsMiddleware::class);
	$services->set(ApiAuthMiddleware::class);
	$services->set(ApiCacheMiddleware::class);
	$services->set(ApiRequestMiddleware::class);

	$services->alias(TenantResolverInterface::class, TenantResolverChain::class);
	$services->alias(LoginProviderInterface::class, LoginProviderChain::class);
};
