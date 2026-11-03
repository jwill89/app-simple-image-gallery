<?php

namespace Gallery\Core;

/**
 * CacheGroup
 *
 * The set of response-cache groups used for targeted invalidation. Mutations
 * invalidate the groups they affect (e.g. tagging a media item clears both
 * Media and Tags). Using an enum instead of bare 'media'/'tags' string
 * literals makes typos a compile-time error and keeps the vocabulary in one
 * place. ResponseCache itself stays string-keyed; controllers pass $group->value.
 */
enum CacheGroup: string
{
    case Media = 'media';
    case Tags = 'tags';
}
