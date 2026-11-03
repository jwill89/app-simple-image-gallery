<?php

declare(strict_types=1);

namespace Tests\Support\Contract;

/** The response headers that are part of the contract; the recorder kept only these. */
final class Headers
{
    public const array CONTRACT = [
        'content-type',
        'x-cache',
        'x-ratelimit-remaining',
        'retry-after',
        'cache-control',
        'allow',
        'access-control-allow-origin',
        'access-control-allow-credentials',
        'access-control-allow-headers',
        'access-control-allow-methods',
        'access-control-max-age',
        'x-frame-options',
        'x-content-type-options',
    ];
}
