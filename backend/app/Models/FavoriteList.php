<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * One of an account's lists of media. Each account has one default list (the
 * heart on a card adds to it), which can be renamed but not deleted. Names are
 * unique within an account, without regard to case.
 *
 * @property int $list_id
 * @property int $user_id
 * @property string $name
 * @property bool $is_default
 * @property-read int|null $media_count Loaded by withCount('media') or loadCount('media').
 */
#[Table(name: 'favorite_lists', key: 'list_id')]
#[Fillable(['name', 'is_default'])]
class FavoriteList extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsToMany<Media, $this> */
    public function media(): BelongsToMany
    {
        return $this->belongsToMany(Media::class, 'favorite_list_items', 'list_id', 'media_id')->withPivot('added_at');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['is_default' => 'boolean'];
    }
}
