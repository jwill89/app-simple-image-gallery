<?php

declare(strict_types=1);

namespace App\Services\Media;

/** What `gallery:regenerate` rebuilds from a media item's file. */
enum MediaDerivative: string
{
    /** The 1x and 2x WebP thumbnails. */
    case Thumbnails = 'thumbnails';

    /**
     * The perceptual fingerprint of an image. Run it for every image after
     * anything changes how fingerprints are computed: old and new ones can't
     * be compared.
     */
    case Fingerprints = 'fingerprints';

    /** Width, height, duration and file size. */
    case Metadata = 'metadata';
}
