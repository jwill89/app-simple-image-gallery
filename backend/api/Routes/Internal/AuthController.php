<?php

namespace Routes\Internal;

use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Gallery\Core\Configuration;
use Gallery\Core\DatabaseConnection;
use Gallery\Core\RateLimiter;
use OpenApi\Spec as OAS;

/**
 * AuthController
 *
 * Admin authentication: exchanges the shared admin password for a 24-hour
 * bearer token. Login is throttled in its own rate-limit bucket (separate from
 * the global per-IP limiter) to slow password brute-forcing.
 */
class AuthController extends AbstractController
{
    /**
     * POST /auth/login — Exchange the admin password for a bearer token.
     * Body: { password }. Returns { token } valid for 24 hours.
     */
    #[OAS\Operation\Post(
        path: '/auth/login',
        summary: 'Log in as admin',
        tags: ['Auth'],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['password'],
                properties: [new OAS\Property(property: 'password', schema: new OAS\Schema(type: 'string'))]
            )
        ),
        responses: [
            new OAS\Response(
                response: 200,
                description: 'Issued token',
                content: new OAS\MediaType\Json(ref: '#/components/schemas/LoginResponse')
            ),
            new OAS\Response(response: 401, description: 'InvalidPassword', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 429, description: 'TooManyAttempts', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 503, description: 'AdminNotConfigured', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function login(Request $request, Response $response): Response
    {
        $ip = $request->getServerParams()['REMOTE_ADDR'] ?? 'unknown';

        // Stricter throttle for login attempts specifically, in its own bucket
        // (separate from the global per-IP limiter) to slow password brute-forcing.
        $loginLimiter = new RateLimiter(DatabaseConnection::getInstance(), 10, 300); // 10 attempts / 5 min
        $loginCheck = $loginLimiter->check('login:' . $ip);
        if (!$loginCheck['allowed']) {
            $this->logger->warning('Login rate limit exceeded', ['ip' => $ip]);
            return $this->error($response, 'TooManyAttempts', 429, 'Too many login attempts. Please wait a few minutes and try again.')
                ->withHeader('Retry-After', (string) $loginCheck['retry_after']);
        }

        // Refuse logins entirely if no admin password is configured, so the
        // 'changeme' development default can never grant access in production.
        if (!Configuration::isAdminConfigured()) {
            $this->logger->error('Login attempted but GALLERY_ADMIN_PASSWORD is not configured', ['ip' => $ip]);
            return $this->error($response, 'AdminNotConfigured', 503, 'Admin access is not configured on the server.');
        }

        $password = $this->stringParam($this->parsedBody($request), 'password');

        if (hash_equals(Configuration::getAdminPassword(), $password)) {
            $token = bin2hex(random_bytes(32));
            $db = DatabaseConnection::getInstance();

            $db->exec('DELETE FROM auth_tokens WHERE created_at < ' . (time() - 86400));

            $stmt = $db->prepare('INSERT INTO auth_tokens (token, created_at) VALUES (:token, :time)');
            $stmt->execute([':token' => $token, ':time' => time()]);

            $this->logger->info('Admin login successful', ['ip' => $ip]);
            return $this->success($response, ['token' => $token]);
        }

        $this->logger->warning('Admin login failed', ['ip' => $ip]);
        return $this->error($response, 'InvalidPassword', 401, 'The password is incorrect.');
    }
}
