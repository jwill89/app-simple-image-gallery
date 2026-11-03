<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Table;
use Illuminate\Database\Eloquent\Model;

/**
 * A Danbooru tag name imported under a different gallery tag name. Tags
 * without a mapping import with their underscores turned into spaces.
 */
#[Table(name: 'danbooru_tag_map', timestamps: false)]
#[Fillable(['danbooru_tag', 'gallery_tag'])]
class DanbooruTagMapping extends Model {}
