<?php

declare(strict_types=1);

namespace App\Support;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * Who is reading a public listing. Those routes take no auth middleware, since
 * anyone may read them; a browser's session, when it is signed in, still names
 * the account, whose blocked tags then leave its listings.
 */
final class Viewer
{
    public static function of(Request $request): ?User
    {
        $user = $request->user();

        return $user instanceof User ? $user : null;
    }

    /**
     * The tags whose media the request's account doesn't see.
     *
     * @return list<int>
     */
    public static function hiddenTags(Request $request): array
    {
        return self::of($request)?->blockedTagIds() ?? [];
    }

    /**
     * What a cached listing's key adds for these hidden tags: nothing when
     * there are none, so every account that blocks nothing shares one entry
     * with signed-out readers.
     *
     * @param list<int> $hidden
     */
    public static function cacheKey(array $hidden): string
    {
        return $hidden === [] ? '' : ':hiding:' . implode(',', $hidden);
    }
}
