<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\UserUpdateRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Log;
use OpenApi\Spec as OAS;

/**
 * Accounts, for admins: who has one, who is an admin, a new password for
 * someone who lost theirs, and removing an account. There is always at least
 * one admin: the last one can't be demoted or deleted.
 */
final class UserController extends Controller
{
    /**
     * GET /users — Every account, by username.
     */
    #[OAS\Operation\Get(
        path: '/users',
        operationId: 'listUsers',
        summary: 'List accounts',
        tags: ['Users'],
        security: [new OAS\Security\Requirement(scheme: 'bearerAuth')],
        responses: [
            new OAS\Response(response: 200, description: 'The accounts', content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/User'))),
            new OAS\Response(response: 403, description: 'Forbidden', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function index(): AnonymousResourceCollection
    {
        return UserResource::collection(User::query()->orderBy('username')->get());
    }

    /**
     * PATCH /users/{user_id} — Make an account an admin or not, or set its
     * password (which signs it out everywhere).
     */
    #[OAS\Operation\Patch(
        path: '/users/{user_id}',
        operationId: 'updateUser',
        summary: 'Change an account',
        tags: ['Users'],
        security: [new OAS\Security\Requirement(scheme: 'bearerAuth')],
        parameters: [new OAS\Parameter(name: 'user_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(properties: [
                new OAS\Property(property: 'is_admin', schema: new OAS\Schema(type: 'boolean')),
                new OAS\Property(property: 'password', schema: new OAS\Schema(type: 'string', maxLength: 200, minLength: 8)),
            ]),
        ),
        responses: [
            new OAS\Response(response: 200, description: 'The account', content: new OAS\MediaType\Json(ref: '#/components/schemas/User')),
            new OAS\Response(response: 400, description: 'WeakPassword', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 404, description: 'UserNotFound', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 409, description: 'LastAdmin', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function update(UserUpdateRequest $request, User $user): UserResource
    {
        if ($request->has('is_admin') && !$request->boolean('is_admin') && $user->isLastAdmin()) {
            throw new ApiException('LastAdmin', 409, 'The last admin can\'t stop being one. Make another admin first.');
        }

        if ($request->has('is_admin')) {
            $user->is_admin = $request->boolean('is_admin');
        }
        if ($request->has('password')) {
            $user->password = $request->string('password')->toString();
            $user->tokens()->delete();
        }
        $user->save();
        Log::info('Account changed by an admin', ['user_id' => $user->id, 'by' => $request->user()?->getAuthIdentifier()]);

        return new UserResource($user);
    }

    /**
     * DELETE /users/{user_id} — Delete an account, with its lists, blocked
     * tags and saved searches.
     */
    #[OAS\Operation\Delete(
        path: '/users/{user_id}',
        operationId: 'deleteUser',
        summary: 'Delete an account',
        tags: ['Users'],
        security: [new OAS\Security\Requirement(scheme: 'bearerAuth')],
        parameters: [new OAS\Parameter(name: 'user_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        responses: [
            new OAS\Response(response: 204, description: 'Deleted'),
            new OAS\Response(response: 404, description: 'UserNotFound', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 409, description: 'LastAdmin', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ],
    )]
    public function destroy(Request $request, User $user): Response
    {
        if ($user->isLastAdmin()) {
            throw new ApiException('LastAdmin', 409, 'The last admin account can\'t be deleted. Make another admin first.');
        }

        $user->tokens()->delete();
        $user->delete();
        Log::info('Account deleted by an admin', ['user_id' => $user->id, 'by' => $request->user()?->getAuthIdentifier()]);

        return response()->noContent();
    }
}
