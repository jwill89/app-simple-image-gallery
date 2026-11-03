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
use Illuminate\Support\Str;
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;

/**
 * An account: a username (unique without regard to case) and a password, and
 * any passkeys it adds to sign in without the password. An admin is an
 * account with is_admin set; the rest keep their own favorite lists, blocked
 * tags and saved searches, and browse as anyone may.
 *
 * @property int $id
 * @property string $username
 * @property bool $is_admin
 */
#[Fillable(['username', 'password', 'is_admin'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;
    use PasskeyAuthenticatable;

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

    /** What a passkey prompt names the account by: its username, as sign-in does. */
    public function getPasskeyUsername(): string
    {
        return $this->username;
    }

    public function getPasskeyDisplayName(): string
    {
        return $this->username;
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
     * A new password, which signs the account out everywhere: each session
     * ends on its next request, when AuthenticateSession finds the password it
     * was signed in with gone, and each remember cookie names a token that no
     * longer exists. A browser meant to stay signed in signs in again
     * (AccountController::changePassword).
     */
    public function setNewPassword(string $password): void
    {
        $this->password = $password;
        $this->setRememberToken(Str::random(60));
        $this->save();
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
