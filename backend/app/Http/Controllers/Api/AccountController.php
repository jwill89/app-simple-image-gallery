<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\PasswordChangeRequest;
use App\Http\Resources\TagResource;
use App\Models\Tag;
use App\Models\User;
use App\Support\Login;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use App\OpenApi\ProblemJson;
use OpenApi\Spec as OAS;

/**
 * The signed-in account's own settings: its password, the account itself, and
 * the tags it blocks. A wrong current password is 403 IncorrectPassword, not
 * 401, which the app reads as "signed out".
 */
final class AccountController extends Controller
{
    /**
     * PATCH /account/password — Change the password. Every other browser
     * signed in as the account is signed out; this one stays, and stays kept
     * signed in if it was.
     */
    #[OAS\Operation\Patch(
        path: '/account/password',
        operationId: 'changePassword',
        summary: 'Change your password',
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        requestBody: new OAS\RequestBody(required: true, content: new OAS\MediaType\Json(ref: '#/components/schemas/PasswordChangeBody')),
        responses: [
            new OAS\Response(response: 204, description: 'Changed'),
            new OAS\Response(response: 403, description: 'IncorrectPassword', content: new ProblemJson()),
            new OAS\Response(response: 422, description: 'MissingCredentials, WeakPassword', content: new ProblemJson()),
        ],
    )]
    public function changePassword(PasswordChangeRequest $request): Response
    {
        $user = self::user($request);
        self::confirmPassword($user, $request->string('currentPassword')->toString());

        $remembered = Login::remembered($request);
        $user->setNewPassword($request->string('password')->toString());
        Login::start($user, $remembered);

        return response()->noContent();
    }

    /**
     * DELETE /account — Delete the account, with its lists, blocked tags and
     * saved searches. Body: { password }. An admin can't (another admin removes
     * the role first), and the last admin least of all.
     */
    #[OAS\Operation\Delete(
        path: '/account',
        operationId: 'deleteAccount',
        summary: 'Delete your account',
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        requestBody: new OAS\RequestBody(required: true, content: new OAS\MediaType\Json(ref: '#/components/schemas/PasswordBody')),
        responses: [
            new OAS\Response(response: 204, description: 'Deleted'),
            new OAS\Response(response: 403, description: 'IncorrectPassword, OwnAdminAccount (an admin can\'t delete their own account)', content: new ProblemJson()),
            new OAS\Response(response: 409, description: 'LastAdmin', content: new ProblemJson()),
            new OAS\Response(response: 422, description: 'MissingCredentials', content: new ProblemJson()),
        ],
    )]
    public function destroy(Request $request): Response
    {
        $user = self::user($request);
        $password = $request->input('password');
        if (!is_string($password) || $password === '') {
            throw new ApiException('MissingCredentials', 422, 'Your password is required.', [], [['message' => 'Your password is required.', 'location' => 'body.password']]);
        }
        self::confirmPassword($user, $password);
        if ($user->isLastAdmin()) {
            throw new ApiException('LastAdmin', 409, 'The last admin account can\'t be deleted. Make another admin first.');
        }
        if ($user->is_admin) {
            throw new ApiException('OwnAdminAccount', 403, 'You can\'t delete your own account while you are an admin. Ask another admin to remove your admin role first.');
        }

        Login::end($request);
        $user->delete();
        Log::info('Account deleted', ['user_id' => $user->id]);

        return response()->noContent();
    }

    /**
     * GET /account/blocked-tags — The tags whose media this account doesn't
     * see in listings, by name.
     */
    #[OAS\Operation\Get(
        path: '/account/blocked-tags',
        operationId: 'listBlockedTags',
        summary: 'Your blocked tags',
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        responses: [
            new OAS\Response(response: 200, description: 'The blocked tags', content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/Tag'))),
        ],
    )]
    public function blockedTags(Request $request): AnonymousResourceCollection
    {
        return TagResource::collection(self::user($request)->blockedTags()->orderBy('tag_name')->get());
    }

    /**
     * PUT /account/blocked-tags/{tagId} — Block a tag: media carrying it
     * leaves this account's listings. Blocking it again changes nothing.
     */
    #[OAS\Operation\Put(
        path: '/account/blocked-tags/{tagId}',
        operationId: 'blockTag',
        summary: 'Block a tag',
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [new OAS\Parameter(name: 'tagId', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        responses: [
            new OAS\Response(response: 204, description: 'Blocked'),
            new OAS\Response(response: 404, description: 'TagNotFound', content: new ProblemJson()),
        ],
    )]
    public function blockTag(Request $request, Tag $tag): Response
    {
        self::user($request)->blockedTags()->syncWithoutDetaching([$tag->tag_id]);

        return response()->noContent();
    }

    /**
     * DELETE /account/blocked-tags/{tagId} — Unblock a tag.
     */
    #[OAS\Operation\Delete(
        path: '/account/blocked-tags/{tagId}',
        operationId: 'unblockTag',
        summary: 'Unblock a tag',
        tags: ['Account'],
        security: [new OAS\Security\Requirement(scheme: 'sessionCookie')],
        parameters: [new OAS\Parameter(name: 'tagId', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        responses: [
            new OAS\Response(response: 204, description: 'Unblocked'),
            new OAS\Response(response: 404, description: 'TagNotFound', content: new ProblemJson()),
        ],
    )]
    public function unblockTag(Request $request, Tag $tag): Response
    {
        self::user($request)->blockedTags()->detach($tag->tag_id);

        return response()->noContent();
    }

    /** The signed-in account (the auth middleware has made sure there is one). */
    public static function user(Request $request): User
    {
        $user = $request->user();

        return $user instanceof User ? $user : throw new ApiException('Unauthorized', 401, 'Authentication is required. Please log in and try again.');
    }

    /** Refuse the request (403 IncorrectPassword) unless $password is the account's. */
    public static function confirmPassword(User $user, string $password): void
    {
        if (!Hash::check($password, $user->password)) {
            throw new ApiException('IncorrectPassword', 403, 'That isn\'t your current password.');
        }
    }
}
