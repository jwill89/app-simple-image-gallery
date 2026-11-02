<?php

declare(strict_types=1);

namespace Gallery\Core;

/**
 * How much two images differ in the region where they differ most.
 *
 * A perceptual hash and a whole-image SSIM both average over the picture, so
 * two variants of one artwork (a changed expression, an added detail) come out
 * as near-identical: in this gallery, variants hashed as close as distance 0
 * and scored SSIM up to 0.97. A re-saved or resized copy differs a little
 * everywhere; a variant differs a lot somewhere. This measures the somewhere.
 *
 * Both images are resampled to the same square and smoothed with a 3x3 box
 * blur, which washes out compression noise. The overall colour offset between
 * them is removed per channel, so a copy whose colours shifted as a whole (a
 * different colour profile, a brightened re-save) isn't counted as differing
 * everywhere. The result is the mean absolute difference, in 0-255 levels, of
 * the worst region on any of R, G and B.
 */
final class RegionDifference
{
    /** Side of the square both images are resampled to. */
    public const int SIZE = 256;

    /** Side of one region: 1/32 of the image each way, 1,024 regions. */
    public const int REGION = 8;

    /**
     * @param positive-int $size
     * @param positive-int $region
     */
    public static function between(\GdImage $a, \GdImage $b, int $size = self::SIZE, int $region = self::REGION): float
    {
        $pixelsA = self::smoothedPixels($a, $size);
        $pixelsB = self::smoothedPixels($b, $size);
        $count = $size * $size;
        $worst = 0.0;

        foreach ([16, 8, 0] as $shift) {
            $diff = [];
            $total = 0;
            for ($i = 0; $i < $count; $i++) {
                $d = (($pixelsA[$i] >> $shift) & 0xFF) - (($pixelsB[$i] >> $shift) & 0xFF);
                $diff[] = $d;
                $total += $d;
            }
            $offset = $total / $count;

            for ($y = 0; $y + $region <= $size; $y += $region) {
                for ($x = 0; $x + $region <= $size; $x += $region) {
                    $sum = 0.0;
                    for ($j = $y; $j < $y + $region; $j++) {
                        for ($i = $x; $i < $x + $region; $i++) {
                            $sum += abs($diff[$j * $size + $i] - $offset);
                        }
                    }
                    $worst = max($worst, $sum / ($region * $region));
                }
            }
        }

        return round($worst, 1);
    }

    /**
     * The image resampled to a $size square and box-blurred, as packed
     * 0xRRGGBB values row by row.
     *
     * @param positive-int $size
     * @return list<int>
     */
    private static function smoothedPixels(\GdImage $image, int $size): array
    {
        $square = imagecreatetruecolor($size, $size);
        imagecopyresampled($square, $image, 0, 0, 0, 0, $size, $size, imagesx($image), imagesy($image));
        imageconvolution($square, [[1, 1, 1], [1, 1, 1], [1, 1, 1]], 9, 0);

        $pixels = [];
        for ($y = 0; $y < $size; $y++) {
            for ($x = 0; $x < $size; $x++) {
                $colour = imagecolorat($square, $x, $y);
                if ($colour === false) {
                    throw new \RuntimeException("Could not read pixel ({$x}, {$y}) of the resampled image.");
                }
                $pixels[] = $colour;
            }
        }

        return $pixels;
    }
}
