<?php

use Symfony\Component\DependencyInjection\Loader\Configurator\ContainerConfigurator;

return static function (ContainerConfigurator $containerConfigurator): void {
	$services = $containerConfigurator->services();
	$services->defaults()
		->private()
		->autowire()
		->autoconfigure();

	$services->load('SGalinski\\SgApiCore\\', __DIR__ . '/../Classes/');
};
