<?php

declare(strict_types=1);

namespace App\Support;

/**
 * The response-cache groups a write invalidates: tagging a media item, for
 * example, changes both what media lists show and what tag lists count.
 */
enum CacheGroup: string
{
    case Media = 'media';
    case Tags = 'tags';
}
