<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A tag search an account keeps by name: the query as the gallery's URL writes
 * it ("cat,-dog", or "untagged"). Its tags aren't checked, so a search
 * outlives a tag deleted since; the search then skips that name, as typed
 * searches do.
 *
 * @property int $search_id
 * @property int $user_id
 * @property string $name
 * @property string $query
 */
#[Table(name: 'saved_searches', key: 'search_id')]
#[Fillable(['name', 'query'])]
class SavedSearch extends Model
{
    /** @return BelongsTo<User, $this> */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
