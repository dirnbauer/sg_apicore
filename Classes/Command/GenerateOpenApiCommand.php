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

namespace SGalinski\SgApiCore\Command;

use JsonException;
use ReflectionException;
use SGalinski\SgApiCore\Service\OpenApiService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * CLI Command to generate OpenAPI specification files
 */
class GenerateOpenApiCommand extends Command {
	public const string COMMAND_DESCRIPTION = 'Generates an OpenAPI specification file for a specific API and version.';

	/**
	 * @param OpenApiService $openApiService
	 */
	public function __construct(protected readonly OpenApiService $openApiService) {
		parent::__construct();
	}

	/**
	 * Configure the command
	 */
	protected function configure(): void {
		$this->setName('api:openapi:generate')
			->setDescription(self::COMMAND_DESCRIPTION)
			->setHelp('Example: typo3 api:openapi:generate --api=public --api-version=1 --out=var/openapi/public-v1.json')
			->addOption('api', NULL, InputOption::VALUE_REQUIRED, 'The API ID (e.g. public)', 'public')
			->addOption('api-version', NULL, InputOption::VALUE_REQUIRED, 'The API version (e.g. 1)', '1')
			->addOption('format', NULL, InputOption::VALUE_REQUIRED, 'The output format (currently only: json)', 'json')
			->addOption('out', NULL, InputOption::VALUE_REQUIRED, 'The output file path');
	}

	/**
	 * Execute the command
	 *
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return int
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$io = new SymfonyStyle($input, $output);
		$apiId = trim((string) $input->getOption('api'));
		$version = trim((string) $input->getOption('api-version'));
		$format = strtolower(trim((string) $input->getOption('format')));
		$out = (string) $input->getOption('out');

		if ($apiId === '') {
			$io->error('The option "--api" must not be empty.');
			return Command::FAILURE;
		}

		if ($version === '') {
			$io->error('The option "--api-version" must not be empty.');
			return Command::FAILURE;
		}

		if ($format !== 'json') {
			$io->error('Currently only "json" format is supported.');
			return Command::FAILURE;
		}

		try {
			$spec = $this->openApiService->generateSpec($apiId, $version);
			$json = json_encode($spec, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
		} catch (ReflectionException|JsonException $exception) {
			$io->error('Could not generate OpenAPI specification: ' . $exception->getMessage());
			return Command::FAILURE;
		}

		if ($out === '') {
			$output->writeln($json);
		} else {
			$result = file_put_contents($out, $json . PHP_EOL, LOCK_EX);
			if ($result === FALSE) {
				$io->error('Could not write to file: ' . $out);
				return Command::FAILURE;
			}
			$io->success('OpenAPI specification generated successfully: ' . $out);
		}

		return Command::SUCCESS;
	}
}
