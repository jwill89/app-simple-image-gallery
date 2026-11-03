<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A pair the duplicate scan reported that the user marked as not duplicates.
 * Stored smaller media id first. The key is the pair (media_id_1, media_id_2),
 * which Eloquent can't address, so rows are inserted and read but never
 * updated or deleted through the model.
 */
#[Table(name: 'dismissed_duplicates', key: false, incrementing: false, timestamps: false)]
class DismissedDuplicate extends Model
{
    protected function casts(): array
    {
        return [
            'media_id_1' => 'integer',
            'media_id_2' => 'integer',
            'dismissed_at' => 'integer',
        ];
    }
}
