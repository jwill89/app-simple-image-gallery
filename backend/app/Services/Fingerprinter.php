<?php

declare(strict_types=1);

namespace App\Services;

use Intervention\Image\Drivers\Gd\Driver as GdDriver;
use Intervention\ImageHash\ImageHasher;
use Intervention\ImageHash\Strategies\PerceptualStrategy;

/**
 * The perceptual fingerprint the duplicate scan compares images by.
 *
 * A fingerprint is only comparable with ones computed the same way, so this is
 * exactly the Slim app's: intervention/imagehash's pHash, always through GD,
 * never whichever of GD or Imagick is loaded (the two resample differently).
 * Change any of that and every stored fingerprint must be recomputed.
 */
final class Fingerprinter
{
    /**
     * The image's 64-bit perceptual hash as a string of 0s and 1s, the form
     * media.bits_fingerprint stores.
     */
    public function fingerprint(string $path): string
    {
        return ImageHasher::create(GdDriver::class, new PerceptualStrategy())->hash($path)->toBits();
    }
}
