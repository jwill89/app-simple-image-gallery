<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * The admin the Sanctum tokens issued at login belong to. Login checks
 * GALLERY_ADMIN_PASSWORD, so a user has no password of its own.
 */
class User extends Authenticatable
{
    use HasApiTokens;
    /** @use HasFactory<UserFactory> */
    use HasFactory;
}
