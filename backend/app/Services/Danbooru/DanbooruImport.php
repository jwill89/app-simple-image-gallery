<?php

declare(strict_types=1);

namespace App\Services\Danbooru;

use App\Exceptions\ApiException;

/** What importing a media item's tags from Danbooru did. */
final readonly class DanbooruImport
{
    /**
     * @param string $method How the post was found: md5, iqdb or post_id ("none" when it wasn't).
     * @param int $tagsApplied Tags from the post given to the item, ones it had already included.
     * @param int $tagsCreated Gallery tags created for names that didn't exist.
     * @param ?DanbooruFailure $failure Why nothing was found, when Danbooru failed rather than had no match.
     * @param ?string $ray Cloudflare's Ray ID, when the failure is a block.
     */
    public function __construct(
        public bool $found,
        public string $method = 'none',
        public int $tagsApplied = 0,
        public int $tagsCreated = 0,
        public ?DanbooruFailure $failure = null,
        public ?string $ray = null,
    ) {}

    /** The failure as the API reports it, or null when there wasn't one. */
    public function failureException(): ?ApiException
    {
        return $this->failure?->exception($this->ray);
    }
}
