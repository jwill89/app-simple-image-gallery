<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * The admin the Sanctum tokens issued at login belong to. Login checks
 * GALLERY_ADMIN_PASSWORD, so a user has no password of its own.
 */
#[Fillable(['name'])]
class User extends Authenticatable
{
    use HasApiTokens;
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    /** The one admin user, created the first time anyone logs in. */
    public static function admin(): self
    {
        return self::query()->firstOrCreate(['name' => 'Admin']);
    }
}
