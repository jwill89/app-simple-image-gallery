<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A pair of media marked as not duplicates, which the scanner and the report
 * leave out. Stored with the smaller id first.
 */
#[Table(name: 'dismissed_duplicates', key: false, incrementing: false, timestamps: false)]
class DismissedDuplicate extends Model
{
    /**
     * Every dismissed pair, keyed as pairKey() keys them.
     *
     * @return array<string, true>
     */
    public static function pairKeys(): array
    {
        $keys = [];
        foreach (self::query()->toBase()->get(['media_id_1', 'media_id_2']) as $row) {
            $keys[self::pairKey((int) $row->media_id_1, (int) $row->media_id_2)] = true;
        }

        return $keys;
    }

    /** A pair's key, "<smaller id>:<larger id>", whichever order the ids come in. */
    public static function pairKey(int $id1, int $id2): string
    {
        return min($id1, $id2) . ':' . max($id1, $id2);
    }

    protected function casts(): array
    {
        return [
            'media_id_1' => 'integer',
            'media_id_2' => 'integer',
            'dismissed_at' => 'integer',
        ];
    }
}
