<?php

declare(strict_types=1);

namespace Gallery\Tests\Support;

/**
 * Images drawn with GD for tests that need pixels: a stand-in artwork, and the
 * ways a second copy of it can differ. A re-saved copy differs a little
 * everywhere; a variant differs a lot in one small area.
 */
final class TestImages
{
    /**
     * A thumbnail-sized picture with a gradient background and a few shapes, so
     * there are edges for compression to blur and regions to compare.
     */
    public static function artwork(): \GdImage
    {
        $width = 400;
        $height = 560;
        $image = imagecreatetruecolor($width, $height);

        for ($y = 0; $y < $height; $y++) {
            $shade = (int) (40 + 160 * $y / $height);
            imageline($image, 0, $y, $width - 1, $y, self::colour($image, $shade, (int) ($shade * 0.8), 200 - intdiv($shade, 2)));
        }
        imagefilledellipse($image, 200, 180, 220, 240, self::colour($image, 235, 200, 170));
        imagefilledellipse($image, 160, 160, 30, 20, self::colour($image, 40, 60, 120));
        imagefilledellipse($image, 240, 160, 30, 20, self::colour($image, 40, 60, 120));
        imagefilledrectangle($image, 90, 330, 310, 540, self::colour($image, 120, 30, 50));
        imagesetthickness($image, 4);
        imageline($image, 170, 240, 230, 240, self::colour($image, 150, 40, 40));

        return $image;
    }

    /** The image with one small square repainted, as in a variant with one detail changed. */
    public static function variant(\GdImage $image): \GdImage
    {
        $copy = self::copy($image);
        imagefilledrectangle($copy, 150, 150, 173, 173, self::colour($copy, 250, 240, 20));

        return $copy;
    }

    /** The image scaled and re-encoded as JPEG, as in a re-saved copy. */
    public static function resaved(\GdImage $image, float $scale, int $quality): \GdImage
    {
        $scaled = imagescale($image, max(1, (int) round(imagesx($image) * $scale)), -1, IMG_BICUBIC);
        if ($scaled === false) {
            throw new \RuntimeException('Could not scale the image.');
        }

        ob_start();
        imagejpeg($scaled, null, $quality);
        $decoded = imagecreatefromstring((string) ob_get_clean());
        if (!$decoded instanceof \GdImage) {
            throw new \RuntimeException('Could not decode the re-saved JPEG.');
        }

        return $decoded;
    }

    /** The image with every channel shifted by $levels, as in a re-save with a different colour profile. */
    public static function brightened(\GdImage $image, int $levels): \GdImage
    {
        $copy = self::copy($image);
        imagefilter($copy, IMG_FILTER_BRIGHTNESS, $levels);

        return $copy;
    }

    private static function copy(\GdImage $image): \GdImage
    {
        $copy = imagecreatetruecolor(400, 560);
        imagecopy($copy, $image, 0, 0, 0, 0, imagesx($image), imagesy($image));

        return $copy;
    }

    private static function colour(\GdImage $image, int $red, int $green, int $blue): int
    {
        $channel = static fn(int $value): int => max(0, min(255, $value));
        $colour = imagecolorallocate($image, $channel($red), $channel($green), $channel($blue));
        if ($colour === false) {
            throw new \RuntimeException('Could not allocate a colour.');
        }

        return $colour;
    }
}
