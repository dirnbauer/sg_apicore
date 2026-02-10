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

namespace SGalinski\SgApiCore\Controller;

use Doctrine\DBAL\Exception;
use JsonException;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Random\RandomException;
use RuntimeException;
use SGalinski\SgApiCore\Attribute\ApiBodyParam;
use SGalinski\SgApiCore\Attribute\ApiEndpoint;
use SGalinski\SgApiCore\Attribute\ApiLegacyMode;
use SGalinski\SgApiCore\Attribute\ApiResponse;
use SGalinski\SgApiCore\Attribute\ApiRoute;
use SGalinski\SgApiCore\Context\TenantContext;
use SGalinski\SgApiCore\Domain\Repository\TokenRepository;
use SGalinski\SgApiCore\Security\AuthContext;
use SGalinski\SgApiCore\Service\ApiRegistry;
use SGalinski\SgApiCore\Service\ResponseService;
use SGalinski\SgApiCore\Service\TokenService;
use SGalinski\SgApiCore\Service\UserAuthService;
use TYPO3\CMS\Core\Crypto\PasswordHashing\InvalidPasswordHashException;

/**
 * Controller for User Authentication (Login & Refresh)
 */
class UserAuthController {
	/**
	 * @var TokenRepository
	 */
	protected TokenRepository $tokenRepository;

	/**
	 * @var TokenService
	 */
	protected TokenService $tokenService;

	/**
	 * @var ApiRegistry
	 */
	protected ApiRegistry $apiRegistry;

	/**
	 * @var UserAuthService
	 */
	protected UserAuthService $userAuthService;

	/**
	 * @var ResponseService
	 */
	protected ResponseService $responseService;

	/**
	 * @param TokenRepository $tokenRepository
	 * @param TokenService $tokenService
	 * @param ApiRegistry $apiRegistry
	 * @param ResponseService $responseService
	 * @param UserAuthService $userAuthService
	 */
	public function __construct(
		TokenRepository $tokenRepository,
		TokenService $tokenService,
		ApiRegistry $apiRegistry,
		ResponseService $responseService,
		UserAuthService $userAuthService
	) {
		$this->tokenRepository = $tokenRepository;
		$this->tokenService = $tokenService;
		$this->apiRegistry = $apiRegistry;
		$this->responseService = $responseService;
		$this->userAuthService = $userAuthService;
	}

	/**
	 * Authenticates a user and returns access and refresh tokens
	 *
	 * @param ServerRequestInterface $request
	 * @return ResponseInterface
	 * @throws Exception
	 * @throws RandomException
	 * @throws \JsonException
	 * @throws InvalidPasswordHashException
	 */
	#[ApiRoute(path: '/auth/login', methods: ['POST'], authMode: ['user', 'public'])]
	#[ApiEndpoint(summary: 'User login', description: 'Authenticates a user with username and password and returns access and refresh tokens.', tags: ['Authentication'])]
	#[ApiBodyParam(name: 'username', type: 'string', description: 'The username of the user', example: 'jane.doe@example.com')]
	#[ApiBodyParam(name: 'password', type: 'string', description: 'The password of the user', example: 'password123')]
	#[ApiResponse(
		status: 200,
		description: 'Login successful, returns tokens',
		example: [
			'access_token' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...',
			'refresh_token' => '7f8e9a0b1c2d3e4f5g6h7i8j9k0l1m2n...',
			'token_type' => 'Bearer',
			'expires_in' => 3600
		]
	)]
	#[ApiResponse(status: 400, description: 'Missing username or password')]
	#[ApiResponse(status: 401, description: 'Invalid credentials')]
	public function login(ServerRequestInterface $request): ResponseInterface {
		return $this->handleLogin($request);
	}

	/**
	 * Legacy login action for sg_rest compatibility
	 *
	 * @param ServerRequestInterface $request
	 * @return ResponseInterface
	 * @throws Exception
	 * @throws InvalidPasswordHashException
	 * @throws RandomException
	 * @throws \JsonException
	 */
	#[ApiRoute(path: '/auth/legacyLogin', methods: ['POST'], apiId: 'legacy', authMode: ['user', 'public'])]
	#[ApiEndpoint(summary: 'Legacy User login', description: 'Authenticates a user and returns a bearer token in the legacy sg_rest format.', tags: ['Legacy'])]
	#[ApiBodyParam(name: 'username', type: 'string', description: 'The username of the user')]
	#[ApiBodyParam(name: 'password', type: 'string', description: 'The password of the user')]
	#[ApiResponse(status: 200, description: 'Login successful, returns bearerToken')]
	#[ApiLegacyMode(wrapData: FALSE)]
	public function legacyLogin(ServerRequestInterface $request): ResponseInterface {
		// Ensure we are in the legacy API context
		if ($request->getAttribute('api.id') !== 'legacy') {
			return $this->responseService->createErrorResponse(
				'Forbidden',
				'This endpoint is only available for the legacy API.',
				403
			);
		}

		$response = $this->handleLogin($request);
		if ($response->getStatusCode() === 200) {
			$data = json_decode((string) $response->getBody(), TRUE, 512, JSON_THROW_ON_ERROR);
			return $this->responseService->createSuccessResponse([
				'bearerToken' => $data['access_token'] ?? ''
			], [], 200, new ApiLegacyMode(wrapData: FALSE));
		}

		return $response;
	}

	/**
	 * Shared login logic
	 *
	 * @param ServerRequestInterface $request
	 * @return ResponseInterface
	 * @throws Exception
	 * @throws InvalidPasswordHashException
	 * @throws RandomException
	 * @throws \JsonException
	 */
	protected function handleLogin(ServerRequestInterface $request): ResponseInterface {
		$params = $request->getParsedBody();
		$username = $params['username'] ?? $params['user'] ?? '';
		$password = $params['password'] ?? $params['pass'] ?? '';

		if ($username === '' || $password === '') {
			return $this->responseService->createErrorResponse('Bad Request', 'Missing username or password.', 400);
		}

		/** @var TenantContext|null $tenantContext */
		$tenantContext = $request->getAttribute('api.tenant');
		$apiId = (string) $request->getAttribute('api.id');
		$version = (string) ($request->getAttribute('api.version') ?? '1');

		$user = $this->userAuthService->authenticateUser($username, $password, $tenantContext);
		if (!$user) {
			return $this->responseService->createErrorResponse('Unauthorized', 'Invalid credentials.', 401);
		}

		// Scope Handling
		$scopes = ['user'];
		/** @var AuthContext|null $authContext */
		$authContext = $request->getAttribute('api.auth');
		if ($authContext instanceof AuthContext) {
			$scopes = array_unique(array_merge($scopes, $authContext->getScopes()));
		} else {
			// Fallback: If Authorization header contains a valid bearer for this API/tenant, merge its scopes
			$authorization = $request->getHeaderLine('Authorization');
			if (stripos($authorization, 'Bearer ') === 0) {
				$bearer = trim(substr($authorization, 7));
				if ($bearer !== '') {
					$hash = hash('sha256', $bearer);
					$tenantId = $tenantContext?->getTenantId() ?? '';
					$siteRootPageId = $tenantContext?->getSiteRootPageId();
					$tokenRecord = $this->tokenRepository->findByHashApiAndTenant($hash, $apiId, $tenantId, $siteRootPageId, TRUE);
					if ($tokenRecord !== NULL) {
						$expired = ((int) ($tokenRecord['expires_at'] ?? 0) > 0) && ((int) $tokenRecord['expires_at'] < time());
						if (!$expired) {
							$inherited = [];
							if (!empty($tokenRecord['scopes'])) {
								try {
									$inherited = json_decode((string) $tokenRecord['scopes'], TRUE, 512, JSON_THROW_ON_ERROR) ?: [];
								} catch (JsonException) {
									$inherited = [];
								}
							}
							$scopes = array_values(array_unique(array_merge($scopes, $inherited)));
						}
					}
				}
			}
		}

		$tokens = $this->userAuthService->generateTokensForUser(
			$user,
			$apiId,
			$version,
			$tenantContext,
			$scopes
		);

		return $this->responseService->createSuccessResponse($tokens);
	}

	/**
	 * Refreshes an access token using a refresh token
	 *
	 * @param ServerRequestInterface $request
	 * @return ResponseInterface
	 * @throws Exception
	 * @throws RandomException
	 * @throws \JsonException
	 */
	#[ApiRoute(path: '/auth/refresh', methods: ['POST'], authMode: ['user', 'public'])]
	#[ApiEndpoint(summary: 'Refresh access token', description: 'Exchange a refresh token for a new access token and a new refresh token (rotation).', tags: ['Authentication'])]
	#[ApiBodyParam(name: 'refresh_token', type: 'string', description: 'The refresh token obtained during login', example: '7f8e9a0b1c2d3e4f5g6h7i8j9k0l1m2n...')]
	#[ApiResponse(
		status: 200,
		description: 'Success, returns new access and refresh tokens',
		example: [
			'access_token' => 'eyJhbGciOiJIUzI1NiIsInR5cCI6IkpXVCJ9...',
			'refresh_token' => 'a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6...',
			'token_type' => 'Bearer',
			'expires_in' => 3600
		]
	)]
	#[ApiResponse(status: 400, description: 'Missing refresh_token parameter')]
	#[ApiResponse(status: 401, description: 'Invalid or expired refresh token')]
	public function refresh(ServerRequestInterface $request): ResponseInterface {
		$params = $request->getParsedBody();
		$refreshToken = $params['refresh_token'] ?? '';

		if ($refreshToken === '') {
			return $this->responseService->createErrorResponse('Bad Request', 'Missing refresh_token parameter.', 400);
		}

		/** @var TenantContext|null $tenantContext */
		$tenantContext = $request->getAttribute('api.tenant');
		$apiId = (string) $request->getAttribute('api.id');
		$version = (string) ($request->getAttribute('api.version') ?? '1');

		try {
			$tokens = $this->userAuthService->refreshTokens(
				$refreshToken,
				$apiId,
				$version,
				$tenantContext
			);
		} catch (\RuntimeException $e) {
			return $this->responseService->createErrorResponse('Unauthorized', $e->getMessage(), 401);
		}

		return $this->responseService->createSuccessResponse($tokens);
	}
}
