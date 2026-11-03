<?php

declare(strict_types=1);

namespace App\Http\Controllers;

abstract class Controller
{
    /**
     * A route's id segment as a positive integer, or null when it isn't one
     * ("0", "-1", "abc"). Endpoints refuse those with 400 before any lookup.
     */
    protected static function positiveId(string $segment): ?int
    {
        return ctype_digit($segment) && (int) $segment > 0 ? (int) $segment : null;
    }
}
