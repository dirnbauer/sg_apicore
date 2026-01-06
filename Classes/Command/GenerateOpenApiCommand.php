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

namespace SGalinski\SgApiCore\Command;

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
	/**
	 * @var OpenApiService
	 */
	protected OpenApiService $openApiService;

	/**
	 * @param OpenApiService $openApiService
	 */
	public function __construct(OpenApiService $openApiService) {
		$this->openApiService = $openApiService;
		parent::__construct();
	}

	/**
	 * Configure the command
	 */
	protected function configure(): void {
		$this->setName('api:openapi:generate')
			->setDescription('Generates an OpenAPI specification file for a specific API and version.')
			->addOption('api', NULL, InputOption::VALUE_REQUIRED, 'The API ID (e.g. public)', 'public')
			->addOption('api-version', NULL, InputOption::VALUE_REQUIRED, 'The API version (e.g. 1)', '1')
			->addOption('format', NULL, InputOption::VALUE_REQUIRED, 'The output format (json)', 'json')
			->addOption('out', NULL, InputOption::VALUE_REQUIRED, 'The output file path');
	}

	/**
	 * Execute the command
	 *
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return int
	 * @throws \JsonException
	 * @throws \ReflectionException
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$io = new SymfonyStyle($input, $output);
		$apiId = (string) $input->getOption('api');
		$version = (string) $input->getOption('api-version');
		$format = (string) $input->getOption('format');
		$out = (string) $input->getOption('out');

		if ($format !== 'json') {
			$io->error('Currently only "json" format is supported.');
			return Command::FAILURE;
		}

		$spec = $this->openApiService->generateSpec($apiId, $version);
		$json = json_encode($spec, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

		if ($out === '') {
			$output->writeln($json);
		} else {
			$result = file_put_contents($out, $json);
			if ($result === FALSE) {
				$io->error('Could not write to file: ' . $out);
				return Command::FAILURE;
			}
			$io->success('OpenAPI specification generated successfully: ' . $out);
		}

		return Command::SUCCESS;
	}
}
