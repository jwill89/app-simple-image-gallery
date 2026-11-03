<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\PasskeyNameRequest;
use App\Http\Requests\PasskeyOptionsRequest;
use App\Http\Requests\PasskeyRequest;
use App\Http\Resources\PasskeyResource;
use App\Http\Resources\UserResource;
use App\Models\User;
use App\Support\Login;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Actions\GenerateRegistrationOptions;
use Laravel\Passkeys\Actions\GenerateVerificationOptions;
use Laravel\Passkeys\Actions\StorePasskey;
use Laravel\Passkeys\Actions\VerifyPasskey;
use Laravel\Passkeys\Passkey;
use Laravel\Passkeys\Support\WebAuthn;
use OpenApi\Spec as OAS;
use Webauthn\Exception\WebauthnException;
use Webauthn\PublicKeyCredentialCreationOptions;
use Webauthn\PublicKeyCredentialRequestOptions;

/**
 * Passkeys: adding one to the signed-in account, and signing in with one
 * instead of a password (laravel/passkeys does the WebAuthn). Each takes two
 * requests: the server sends options with a random challenge, which the
 * session keeps for five minutes, and the browser answers it, signed by the
 * passkey. A challenge is answered once, right or wrong. Signing in with a
 * passkey skips Turnstile, since a passkey can't be guessed or replayed, but
 * counts against the login limit.
 */
final class PasskeyController extends Controller
{
    /** How long a challenge may wait for its answer, in seconds. */
    private const int CHALLENGE_LIFETIME = 300;

    /** Where the session keeps each ceremony's options. */
    private const string SIGN_IN = 'passkey.sign_in';

    private const string ADD = 'passkey.add';

    /**
     * POST /auth/passkey/options — A challenge to sign in with: any passkey
     * saved for this site may answer it, and names its account itself.
     */
    #[OAS\Operation\Post(
        path: '/auth/passkey/options',
        operationId: 'passkeySignInOptions',
        summary: 'Start signing in with a passkey',
        tags: ['Auth'],
        responses: [
            new OAS\Response(response: 200, description: 'The options for navigator.credentials.get()', content: new OAS\MediaType\Json(ref: '#/components/schemas/PasskeyOptions')),
        ],
    )]
    public function signInOptions(Request $request, GenerateVerificationOptions $generate): JsonResponse
    {
        $options = $generate();
        self::keep($request, self::SIGN_IN, WebAuthn::toJson($options), null);

        return new JsonResponse(WebAuthn::toBrowserArray($options));
    }

    /**
     * POST /auth/passkey — Sign this browser in with a passkey's answer to the
     * challenge. Body: { credential, remember? }.
     */
    #[OAS\Operation\Post(
        path: '/auth/passkey',
        operationId: 'passkeySignIn',
        summary: 'Sign in with a passkey',
        tags: ['Auth'],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['credential'],
                properties: [
                    new OAS\Property(property: 'credential', schema: new OAS\Schema(type: 'object', additionalProperties: true, description: 'The PublicKeyCredential navigator.credentials.get() gave, as its toJSON() writes it.')),
                    new OAS\Property(property: 'remember', schema: new OAS\Schema(type: 'boolean', description: 'Keep this browser signed in for 30 days, not just until it closes.')),
                ],
            ),
        ),
        responses: [
            new OAS\Response(response: 200, description: 'Signed in', content: new OAS\MediaType\Json(ref: '#/components/schemas/SignInResponse')),
            new OAS\Response(response: 400, description: 'InvalidPasskey (the answer is unreadable), PasskeyExpired (no challenge waiting, or too old)', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 401, description: 'PasskeyNotAccepted', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 429, description: 'TooManyAttempts', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function signIn(PasskeyRequest $request, VerifyPasskey $verify): JsonResponse
    {
        AuthController::throttleLogin($request);
        $options = self::take($request, self::SIGN_IN, null);
        $credential = $request->credential();

        try {
            $passkey = $verify($credential, WebAuthn::fromJson($options, PublicKeyCredentialRequestOptions::class));
        } catch (ValidationException|WebauthnException $e) {
            Log::warning('Passkey login failed', ['ip' => $request->ip(), 'reason' => $e->getMessage()]);

            throw new ApiException('PasskeyNotAccepted', 401, 'That passkey wasn\'t accepted. It may have been removed from its account.');
        }
        $user = $passkey->user;
        if (!$user instanceof User) {
            throw new ApiException('PasskeyNotAccepted', 401, 'That passkey wasn\'t accepted. It may have been removed from its account.');
        }

        Log::info('Login with a passkey', ['user_id' => $user->id, 'passkey_id' => $passkey->id, 'ip' => $request->ip()]);
        Login::start($user, $request->boolean('remember'));

        return new JsonResponse(['user' => new UserResource($user)->resolve($request)]);
    }

    /**
     * GET /account/passkeys — The account's passkeys, oldest first.
     */
    #[OAS\Operation\Get(
        path: '/account/passkeys',
        operationId: 'listPasskeys',
        summary: 'Your passkeys',
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        responses: [
            new OAS\Response(response: 200, description: 'The passkeys', content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/Passkey'))),
        ],
    )]
    public function index(Request $request): AnonymousResourceCollection
    {
        return PasskeyResource::collection(AccountController::user($request)->passkeys()->orderBy('id')->get());
    }

    /**
     * POST /account/passkeys/options — A challenge to add a passkey with, once
     * the account's password confirms who is asking. Body: { password }.
     */
    #[OAS\Operation\Post(
        path: '/account/passkeys/options',
        operationId: 'passkeyAddOptions',
        summary: 'Start adding a passkey',
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(required: ['password'], properties: [new OAS\Property(property: 'password', schema: new OAS\Schema(type: 'string'))]),
        ),
        responses: [
            new OAS\Response(response: 200, description: 'The options for navigator.credentials.create()', content: new OAS\MediaType\Json(ref: '#/components/schemas/PasskeyOptions')),
            new OAS\Response(response: 400, description: 'MissingCredentials', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 403, description: 'IncorrectPassword', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function addOptions(PasskeyOptionsRequest $request, GenerateRegistrationOptions $generate): JsonResponse
    {
        $user = AccountController::user($request);
        AccountController::confirmPassword($user, $request->string('password')->toString());

        $options = $generate($user);
        self::keep($request, self::ADD, WebAuthn::toJson($options), $user->id);

        return new JsonResponse(WebAuthn::toBrowserArray($options));
    }

    /**
     * POST /account/passkeys — Add the passkey that answered the challenge.
     * Body: { credential, name? }. Unnamed, it is named after where it is kept,
     * when its maker says (Windows Hello, iCloud Keychain, …).
     */
    #[OAS\Operation\Post(
        path: '/account/passkeys',
        operationId: 'addPasskey',
        summary: 'Add a passkey',
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['credential'],
                properties: [
                    new OAS\Property(property: 'credential', schema: new OAS\Schema(type: 'object', additionalProperties: true, description: 'The PublicKeyCredential navigator.credentials.create() gave, as its toJSON() writes it.')),
                    new OAS\Property(property: 'name', schema: new OAS\Schema(type: 'string', maxLength: 64)),
                ],
            ),
        ),
        responses: [
            new OAS\Response(response: 201, description: 'The passkey', content: new OAS\MediaType\Json(ref: '#/components/schemas/Passkey')),
            new OAS\Response(response: 400, description: 'InvalidPasskey, InvalidPasskeyName, PasskeyExpired, PasskeyRejected (its answer didn\'t check out, or it is already added)', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function store(PasskeyRequest $request, StorePasskey $store): JsonResponse
    {
        $user = AccountController::user($request);
        $options = self::take($request, self::ADD, $user->id);
        $credential = $request->credential();
        $name = $request->string('name')->toString();

        try {
            $passkey = $store($user, $name !== '' ? $name : 'Passkey', $credential, WebAuthn::fromJson($options, PublicKeyCredentialCreationOptions::class));
        } catch (ValidationException|WebauthnException $e) {
            Log::warning('Passkey not added', ['user_id' => $user->id, 'reason' => $e->getMessage()]);

            throw new ApiException('PasskeyRejected', 400, 'The passkey could not be added. It may be added already; otherwise, please try again.');
        }
        if ($name === '' && is_string($passkey->authenticator)) {
            $passkey->update(['name' => $passkey->authenticator]);
        }
        Log::info('Passkey added', ['user_id' => $user->id, 'passkey_id' => $passkey->id]);

        return new PasskeyResource($passkey)->response($request)->setStatusCode(201);
    }

    /**
     * PATCH /account/passkeys/{passkey_id} — Rename a passkey. Body: { name }.
     */
    #[OAS\Operation\Patch(
        path: '/account/passkeys/{passkey_id}',
        operationId: 'renamePasskey',
        summary: 'Rename a passkey',
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [new OAS\Parameter(name: 'passkey_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(required: ['name'], properties: [new OAS\Property(property: 'name', schema: new OAS\Schema(type: 'string', maxLength: 64))]),
        ),
        responses: [
            new OAS\Response(response: 200, description: 'The passkey', content: new OAS\MediaType\Json(ref: '#/components/schemas/Passkey')),
            new OAS\Response(response: 400, description: 'InvalidPasskeyName', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 404, description: 'PasskeyNotFound', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function update(PasskeyNameRequest $request, Passkey $passkey): PasskeyResource
    {
        self::own($request, $passkey)->update(['name' => trim($request->string('name')->toString())]);

        return new PasskeyResource($passkey);
    }

    /**
     * DELETE /account/passkeys/{passkey_id} — Remove a passkey: it no longer
     * signs anyone in (the browser or password manager still lists it until
     * it is deleted there).
     */
    #[OAS\Operation\Delete(
        path: '/account/passkeys/{passkey_id}',
        operationId: 'deletePasskey',
        summary: 'Remove a passkey',
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [new OAS\Parameter(name: 'passkey_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        responses: [
            new OAS\Response(response: 204, description: 'Removed'),
            new OAS\Response(response: 404, description: 'PasskeyNotFound', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(Request $request, Passkey $passkey): Response
    {
        self::own($request, $passkey)->delete();
        Log::info('Passkey removed', ['user_id' => $passkey->user_id, 'passkey_id' => $passkey->id]);

        return response()->noContent();
    }

    /** Keep a ceremony's options in the session, replacing any before, for the account they were made for. */
    private static function keep(Request $request, string $key, string $options, ?int $userId): void
    {
        $request->session()->put($key, ['options' => $options, 'user_id' => $userId, 'at' => now()->getTimestamp()]);
    }

    /**
     * A ceremony's options, out of the session for good: a challenge is
     * answered once. Missing, too old, or made for another account, the
     * browser has to start again (400 PasskeyExpired).
     */
    private static function take(Request $request, string $key, ?int $userId): string
    {
        $kept = $request->session()->pull($key);
        $fresh = is_array($kept)
            && is_string($kept['options'] ?? null)
            && ($kept['user_id'] ?? null) === $userId
            && is_int($kept['at'] ?? null)
            && $kept['at'] >= now()->getTimestamp() - self::CHALLENGE_LIFETIME;

        return $fresh ? $kept['options'] : throw new ApiException('PasskeyExpired', 400, 'The passkey request expired. Please try again.');
    }

    /** The passkey, if it is the signed-in account's; someone else's is as missing as a deleted one. */
    private static function own(Request $request, Passkey $passkey): Passkey
    {
        return $passkey->user_id === AccountController::user($request)->id
            ? $passkey
            : throw new ApiException('PasskeyNotFound', 404, 'The passkey could not be found.');
    }
}
