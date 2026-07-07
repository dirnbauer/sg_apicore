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

namespace SGalinski\SgApiCore\Abilities;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Attribute\ApiEndpoint;
use SGalinski\SgApiCore\Attribute\ApiPathParam;
use SGalinski\SgApiCore\Attribute\ApiQueryParam;
use SGalinski\SgApiCore\Attribute\ApiRoute;
use SGalinski\SgApiCore\Attribute\RequireScopes;
use SGalinski\SgApiCore\Security\AuthContext;
use SGalinski\SgApiCore\Service\ResponseService;
use TYPO3\CMS\Core\Http\JsonResponse;
use Webconsulting\Abilities\Domain\AbilityDefinition;
use Webconsulting\Abilities\Domain\AbilityResult;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Execution\AbilityExecutor;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;

/**
 * REST projection of the webconsulting/typo3-abilities registry:
 * one typed, permissioned registry of what the installation can do;
 * these endpoints are generated views of it, never hand-rolled logic.
 *
 * Only wired when EXT:abilities is installed and the
 * activateAbilitiesApi extension setting is enabled — see
 * Configuration/Services.php and ext_localconf.php (fork-only blocks).
 *
 * The /run response body is the verbatim AbilityResult envelope
 * ({ok, data} | {ok, errorCode, error}) — the same contract the CLI
 * and MCP projections emit. Review-gated abilities are never approvable
 * over REST: a bearer token is not a human in the loop.
 */
class AbilitiesController {
	public function __construct(
		protected AbilitiesRegistry $registry,
		protected AbilityExecutor $executor,
		protected ResponseService $responseService
	) {
	}

	#[ApiRoute(path: '/abilities', methods: ['GET'], apiId: 'abilities', version: '1', authMode: 'token')]
	#[ApiEndpoint(
		summary: 'List abilities exposed to the REST surface',
		description: 'Returns the registry definitions (name, contract metadata, scopes, risk tier, side effects) of every ability exposed to "rest". Per-ability input/output JSON schemas are served by GET /abilities/{namespace}/{name}.',
		tags: ['Abilities']
	)]
	#[ApiQueryParam(name: 'category', type: 'string', description: 'Only list abilities of this category')]
	#[RequireScopes(['abilities:read'])]
	public function listAction(ServerRequestInterface $request): ResponseInterface {
		$category = $request->getQueryParams()['category'] ?? NULL;
		$definitions = [];
		foreach ($this->registry->getDefinitions(\is_string($category) ? $category : NULL) as $definition) {
			if ($definition->isExposedTo(ExecutionContext::SURFACE_REST)) {
				$definitions[] = $definition->toArray();
			}
		}

		return $this->responseService->createSuccessResponse([
			'abilities' => $definitions,
			'total' => \count($definitions),
		]);
	}

	#[ApiRoute(path: '/abilities/{namespace}/{name}', methods: ['GET'], apiId: 'abilities', version: '1', authMode: 'token')]
	#[ApiEndpoint(
		summary: 'Describe one ability including its input/output JSON schemas',
		tags: ['Abilities']
	)]
	#[ApiPathParam(name: 'namespace', type: 'string', description: 'Ability namespace, e.g. "system"')]
	#[ApiPathParam(name: 'name', type: 'string', description: 'Ability name, e.g. "site-info"')]
	#[RequireScopes(['abilities:read'])]
	public function describeAction(
		ServerRequestInterface $request,
		string $namespace,
		string $name
	): ResponseInterface {
		$definition = $this->findExposedDefinition($namespace . '/' . $name);
		if ($definition === NULL) {
			return $this->responseService->createErrorResponse('Not Found', 'Unknown ability.', 404);
		}

		$ability = $this->registry->get($definition->name);

		return $this->responseService->createSuccessResponse(
			$definition->toArray() + [
				'inputSchema' => $ability->getInputSchema() ?: new \stdClass(),
				'outputSchema' => $ability->getOutputSchema() ?: new \stdClass(),
			]
		);
	}

	#[ApiRoute(path: '/abilities/{namespace}/{name}/run', methods: ['POST'], apiId: 'abilities', version: '1', authMode: 'token')]
	#[ApiEndpoint(
		summary: 'Execute an ability through the governed pipeline',
		description: 'Runs the ability with the JSON object body as input (send {} for none). The pipeline enforces policy, input schema, token scopes, the ability\'s own permission check and the output contract. The response is the ability result envelope: {ok, data} or {ok, errorCode, error}.',
		tags: ['Abilities']
	)]
	#[ApiPathParam(name: 'namespace', type: 'string', description: 'Ability namespace, e.g. "system"')]
	#[ApiPathParam(name: 'name', type: 'string', description: 'Ability name, e.g. "site-info"')]
	public function runAction(
		ServerRequestInterface $request,
		string $namespace,
		string $name
	): ResponseInterface {
		$definition = $this->findExposedDefinition($namespace . '/' . $name);
		if ($definition === NULL) {
			return $this->responseService->createErrorResponse('Not Found', 'Unknown ability.', 404);
		}

		$input = $request->getParsedBody();
		$objectInput = [];
		if (\is_array($input)) {
			foreach ($input as $key => $value) {
				$objectInput[(string) $key] = $value;
			}
		}

		$authContext = $request->getAttribute('api.auth');
		$grantedScopes = $authContext instanceof AuthContext
			? array_values(array_filter($authContext->getScopes(), is_string(...)))
			: [];

		$result = $this->executor->execute(
			$this->registry->get($definition->name),
			$objectInput,
			new ExecutionContext(
				surface: ExecutionContext::SURFACE_REST,
				grantedScopes: $grantedScopes,
				reviewApproved: FALSE
			)
		);

		return new JsonResponse($result->toArray(), $this->httpStatusFor($result), [
			'X-Content-Type-Options' => 'nosniff',
			'X-Frame-Options' => 'DENY',
		]);
	}

	protected function findExposedDefinition(string $abilityName): ?AbilityDefinition {
		if (!$this->registry->has($abilityName)) {
			return NULL;
		}

		$definition = $this->registry->getDefinition($abilityName);

		return $definition->isExposedTo(ExecutionContext::SURFACE_REST) ? $definition : NULL;
	}

	protected function httpStatusFor(AbilityResult $result): int {
		if ($result->ok) {
			return 200;
		}

		return match ($result->errorCode) {
			AbilityResult::ERROR_INVALID_INPUT => 400,
			AbilityResult::ERROR_POLICY_DENIED,
			AbilityResult::ERROR_PERMISSION_DENIED => 403,
			default => 500,
		};
	}
}
