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
use SGalinski\SgApiCore\Configuration\ExtensionConfiguration;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\McpToolService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Lists effective MCP tools after all filters.
 */
class McpListCommand extends Command {
	/**
	 * @param McpToolService $mcpToolService
	 * @param ApiRegistry $apiRegistry
	 * @param ExtensionConfiguration $extensionConfiguration
	 */
	public function __construct(
		protected McpToolService $mcpToolService,
		protected ApiRegistry $apiRegistry,
		protected ExtensionConfiguration $extensionConfiguration
	) {
		parent::__construct();
	}

	/**
	 * Configure command options.
	 *
	 * @return void
	 */
	protected function configure(): void {
		$this->setName('api:mcp:list')
			->setDescription('Lists exposed MCP tools after config, denylist, and attribute filtering.')
			->addOption('api', NULL, InputOption::VALUE_OPTIONAL, 'Restrict to one API ID')
			->addOption('api-version', NULL, InputOption::VALUE_OPTIONAL, 'Restrict to one API version')
			->addOption('json', NULL, InputOption::VALUE_NONE, 'Output JSON');
	}

	/**
	 * Execute command.
	 *
	 * @param InputInterface $input
	 * @param OutputInterface $output
	 * @return int
	 * @throws JsonException
	 * @throws ReflectionException
	 */
	protected function execute(InputInterface $input, OutputInterface $output): int {
		$io = new SymfonyStyle($input, $output);
		$apiOption = $input->getOption('api');
		$apiVersionOption = $input->getOption('api-version');
		$selectedApi = \is_scalar($apiOption) ? trim((string) $apiOption) : '';
		$selectedVersion = \is_scalar($apiVersionOption) ? trim((string) $apiVersionOption) : '';
		$jsonMode = (bool) $input->getOption('json');

		if (!$this->extensionConfiguration->isMcpEnabled()) {
			$io->warning('MCP is disabled globally (`mcpEnabled = 0`).');
		}

		if ($selectedApi !== '' && !$this->apiRegistry->hasApi($selectedApi)) {
			$io->error('Unknown API ID: ' . $selectedApi);
			return Command::FAILURE;
		}

		$apis = $this->apiRegistry->getApis();
		$apiIds = $selectedApi !== '' ? [$selectedApi] : array_keys($apis);
		/** @var list<array{apiId: string, version: string, tool: string, method: string, path: string, endpointId: string}> $rows */
		$rows = [];
		foreach ($apiIds as $apiId) {
			if (!\is_string($apiId)) {
				continue;
			}
			$apiConfig = $this->apiRegistry->getApi($apiId);
			if ($apiConfig === NULL) {
				continue;
			}

			$versions = $selectedVersion !== '' ? [$selectedVersion] : $this->normalizeStringList($apiConfig['versions'] ?? []);
			foreach ($versions as $version) {
				$authMode = $this->mcpToolService->getAuthModeForApi($apiId, $version);
				$resolvedTools = $this->mcpToolService->listResolvedTools($apiId, $version, $authMode);

				foreach ($resolvedTools as $entry) {
					$rows[] = [
						'apiId' => $apiId,
						'version' => $version,
						'tool' => $entry['tool']['name'],
						'method' => $entry['httpMethod'],
						'path' => $entry['path'],
						'endpointId' => $entry['endpointId'],
					];
				}
			}
		}

		if ($jsonMode) {
			$output->writeln(json_encode($rows, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
			return Command::SUCCESS;
		}

		if ($rows === []) {
			$io->note('No MCP tools exposed for the selected filters.');
			return Command::SUCCESS;
		}

		$io->table(
			['API', 'Version', 'Tool', 'Method', 'Path', 'Endpoint ID'],
			array_map(static fn (array $row): array => [
				$row['apiId'],
				$row['version'],
				$row['tool'],
				$row['method'],
				$row['path'],
				$row['endpointId'],
			], $rows)
		);

		$io->success('Exposed tools: ' . \count($rows));
		return Command::SUCCESS;
	}

	/**
	 * @param mixed $value
	 * @return list<string>
	 */
	private function normalizeStringList(mixed $value): array {
		if (!\is_array($value)) {
			return [];
		}

		$strings = [];
		foreach ($value as $item) {
			if (!\is_scalar($item)) {
				continue;
			}
			$string = trim((string) $item);
			if ($string !== '') {
				$strings[] = $string;
			}
		}
		return array_values(array_unique($strings));
	}
}
