<?php

declare(strict_types=1);

namespace App\Models;

use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Laravel\Sanctum\HasApiTokens;

/**
 * An account: a username (unique without regard to case) and a password. An
 * admin is an account with is_admin set; the rest keep their own favorite
 * lists, blocked tags and saved searches, and browse as anyone may.
 *
 * @property int $id
 * @property string $username
 * @property bool $is_admin
 */
#[Fillable(['username', 'password', 'is_admin'])]
#[Hidden(['password'])]
class User extends Authenticatable
{
    use HasApiTokens;
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    /** How long a login lasts: a day, or 30 with "keep me signed in". */
    public const int SESSION_HOURS = 24;

    public const int REMEMBER_DAYS = 30;

    /** The name of the list every account starts with. */
    public const string DEFAULT_LIST = 'Favorites';

    /** @return HasMany<FavoriteList, $this> */
    public function favoriteLists(): HasMany
    {
        return $this->hasMany(FavoriteList::class);
    }

    /** @return BelongsToMany<Tag, $this> */
    public function blockedTags(): BelongsToMany
    {
        return $this->belongsToMany(Tag::class, 'blocked_tags', 'user_id', 'tag_id');
    }

    /** @return HasMany<SavedSearch, $this> */
    public function savedSearches(): HasMany
    {
        return $this->hasMany(SavedSearch::class);
    }

    /** The list the heart on a card adds to, made the first time it is needed. */
    public function defaultList(): FavoriteList
    {
        return $this->favoriteLists()->firstOrCreate(['is_default' => true], ['name' => self::DEFAULT_LIST]);
    }

    /**
     * The tags whose media this account doesn't see in listings.
     *
     * @return list<int>
     */
    public function blockedTagIds(): array
    {
        return array_values(array_map(intval(...), $this->blockedTags()->orderBy('tags.tag_id')->pluck('tags.tag_id')->all()));
    }

    /**
     * A new login token, for a day or, with $remember, 30. Returned once, as
     * 64 hex characters; Sanctum stores only its SHA-256 hash, and since the
     * token carries no "<id>|" prefix, finds it by that hash.
     */
    public function issueToken(bool $remember = false): string
    {
        $token = bin2hex(random_bytes(32));
        $this->tokens()->create([
            'name' => $remember ? 'remembered' : 'session',
            'token' => hash('sha256', $token),
            'abilities' => ['*'],
            'expires_at' => $remember ? now()->addDays(self::REMEMBER_DAYS) : now()->addHours(self::SESSION_HOURS),
        ]);

        return $token;
    }

    /** Whether another admin would remain without this one. */
    public function isLastAdmin(): bool
    {
        return $this->is_admin && !self::query()->where('is_admin', true)->whereKeyNot($this->id)->exists();
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['password' => 'hashed', 'is_admin' => 'boolean'];
    }
}
