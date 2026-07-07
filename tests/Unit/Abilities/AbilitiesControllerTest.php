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

namespace SGalinski\SgApiCore\Tests\Unit\Abilities;

use Psr\Http\Message\ServerRequestInterface;
use SGalinski\SgApiCore\Abilities\AbilitiesController;
use SGalinski\SgApiCore\Security\AuthContext;
use SGalinski\SgApiCore\Service\ResponseService;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;
use Webconsulting\Abilities\Attribute\AsAbility;
use Webconsulting\Abilities\Domain\ExecutionContext;
use Webconsulting\Abilities\Domain\RiskTier;
use Webconsulting\Abilities\Execution\AbilityExecutor;
use Webconsulting\Abilities\Policy\PolicyProvider;
use Webconsulting\Abilities\Registry\AbilitiesRegistry;
use Webconsulting\Abilities\Registry\AbstractAbility;
use Webconsulting\Abilities\Validation\SchemaValidator;

#[AsAbility(
	name: 'test/echo',
	title: 'Echo',
	description: 'Returns the message.',
	category: 'testing',
	scopes: ['testing:read'],
	riskTier: RiskTier::Low,
	idempotent: TRUE
)]
class RestEchoAbility extends AbstractAbility {
	public function getInputSchema(): array {
		return [
			'type' => 'object',
			'required' => ['message'],
			'properties' => ['message' => ['type' => 'string']],
		];
	}

	public function execute(array $input, ExecutionContext $context): mixed {
		return ['echo' => $input['message'] ?? ''];
	}
}

#[AsAbility(
	name: 'test/hidden',
	title: 'Hidden',
	description: 'Not exposed to REST.',
	category: 'testing',
	expose: ['cli']
)]
class RestHiddenAbility extends AbstractAbility {
	public function execute(array $input, ExecutionContext $context): mixed {
		return NULL;
	}
}

class AbilitiesControllerTest extends UnitTestCase {
	protected AbilitiesController $controller;
	protected ResponseService $responseService;

	protected function setUp(): void {
		parent::setUp();
		$registry = new AbilitiesRegistry([new RestEchoAbility(), new RestHiddenAbility()]);
		$executor = new AbilityExecutor(
			new SchemaValidator(),
			new PolicyProvider('/nonexistent/abilities-policy.yaml')
		);
		$this->responseService = $this->createStub(ResponseService::class);
		$this->responseService->method('createSuccessResponse')->willReturnCallback(
			static fn (mixed $data) => new JsonResponse(['data' => $data], 200)
		);
		$this->responseService->method('createErrorResponse')->willReturnCallback(
			static fn (string $title, string $detail, int $status) => new JsonResponse(['title' => $title], $status)
		);
		$this->controller = new AbilitiesController($registry, $executor, $this->responseService);
	}

	/**
	 * @param mixed $parsedBody
	 * @param array<int, string> $scopes
	 * @param array<string, mixed> $queryParams
	 * @return ServerRequestInterface
	 */
	protected function createRequest(
		mixed $parsedBody = NULL,
		array $scopes = [],
		array $queryParams = []
	): ServerRequestInterface {
		$request = $this->createStub(ServerRequestInterface::class);
		$request->method('getParsedBody')->willReturn($parsedBody);
		$request->method('getQueryParams')->willReturn($queryParams);
		$request->method('getAttribute')->willReturnCallback(
			static function (string $name) use ($scopes): mixed {
				if ($name === 'api.auth') {
					return new AuthContext(apiId: 'abilities', tenantId: '', tokenUid: 1, scopes: $scopes);
				}
				return NULL;
			}
		);
		return $request;
	}

	/**
	 * @param \Psr\Http\Message\ResponseInterface $response
	 * @return array<mixed>
	 */
	protected function decode(\Psr\Http\Message\ResponseInterface $response): array {
		$decoded = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
		$this->assertIsArray($decoded);
		return $decoded;
	}

	/**
	 * @param mixed $value
	 * @return array<mixed>
	 */
	protected function asArray(mixed $value): array {
		$this->assertIsArray($value);
		return $value;
	}

	public function testListActionOnlyContainsRestExposedAbilities(): void {
		$response = $this->controller->listAction($this->createRequest());

		$data = $this->asArray($this->decode($response)['data']);
		$this->assertSame(1, $data['total']);
		$abilities = $this->asArray($data['abilities']);
		$this->assertSame('test/echo', $this->asArray($abilities[0])['name']);
	}

	public function testListActionFiltersByCategory(): void {
		$response = $this->controller->listAction($this->createRequest(queryParams: ['category' => 'other']));

		$this->assertSame(0, $this->asArray($this->decode($response)['data'])['total']);
	}

	public function testDescribeActionReturnsSchemas(): void {
		$response = $this->controller->describeAction($this->createRequest(), 'test', 'echo');

		$data = $this->asArray($this->decode($response)['data']);
		$this->assertSame('test/echo', $data['name']);
		$this->assertSame('object', $this->asArray($data['inputSchema'])['type']);
	}

	public function testDescribeActionReturns404ForUnknownAbility(): void {
		$response = $this->controller->describeAction($this->createRequest(), 'test', 'nope');
		$this->assertSame(404, $response->getStatusCode());
	}

	public function testDescribeActionReturns404ForNotRestExposedAbility(): void {
		$response = $this->controller->describeAction($this->createRequest(), 'test', 'hidden');
		$this->assertSame(404, $response->getStatusCode());
	}

	public function testRunActionExecutesWithGrantedScopes(): void {
		$request = $this->createRequest(['message' => 'hi'], ['testing:read']);

		$response = $this->controller->runAction($request, 'test', 'echo');

		$this->assertSame(200, $response->getStatusCode());
		$body = $this->decode($response);
		$this->assertTrue($body['ok']);
		$this->assertSame(['echo' => 'hi'], $body['data']);
	}

	public function testRunActionDeniesMissingScopeWith403(): void {
		$request = $this->createRequest(['message' => 'hi'], ['other:scope']);

		$response = $this->controller->runAction($request, 'test', 'echo');

		$this->assertSame(403, $response->getStatusCode());
		$body = $this->decode($response);
		$this->assertFalse($body['ok']);
		$this->assertSame('permission_denied', $body['errorCode']);
	}

	public function testRunActionMapsInvalidInputTo400(): void {
		$request = $this->createRequest([], ['testing:read']);

		$response = $this->controller->runAction($request, 'test', 'echo');

		$this->assertSame(400, $response->getStatusCode());
		$this->assertSame('invalid_input', $this->decode($response)['errorCode']);
	}

	public function testRunActionTreatsEmptyBodyAsEmptyObject(): void {
		$request = $this->createRequest(NULL, ['testing:read']);

		$response = $this->controller->runAction($request, 'test', 'echo');

		$this->assertSame(400, $response->getStatusCode());
		$this->assertSame('invalid_input', $this->decode($response)['errorCode']);
	}

	public function testRunActionReturns404ForNotRestExposedAbility(): void {
		$response = $this->controller->runAction($this->createRequest(['x' => 1], ['testing:read']), 'test', 'hidden');
		$this->assertSame(404, $response->getStatusCode());
	}
}
