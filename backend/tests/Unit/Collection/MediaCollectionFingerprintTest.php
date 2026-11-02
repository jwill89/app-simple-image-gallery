<?php

declare(strict_types=1);

namespace Gallery\Tests\Unit\Collection;

use Gallery\Collection\MediaCollection;
use Gallery\Storage\MediaStorage;
use Gallery\Structure\Media;
use Gallery\Tests\Support\DatabaseTestCase;
use Intervention\ImageHash\Hash;
use PHPUnit\Framework\Attributes\CoversClass;

/**
 * Fingerprinting end to end on images drawn with GD: the hash a stored
 * fingerprint comes from, and that the duplicate scan can read it back.
 */
#[CoversClass(MediaCollection::class)]
final class MediaCollectionFingerprintTest extends DatabaseTestCase
{
    private string $directory;

    protected function setUp(): void
    {
        $this->directory = sys_get_temp_dir() . '/gallery-fingerprint-test-' . bin2hex(random_bytes(4)) . '/';
        mkdir($this->directory);
    }

    protected function tearDown(): void
    {
        $files = glob($this->directory . '*');
        foreach ($files === false ? [] : $files as $file) {
            unlink($file);
        }
        rmdir($this->directory);
    }

    /**
     * A $size-pixel square split into a dark and a light half, vertically or
     * horizontally, with a mid-grey disc off-centre: low-frequency structure
     * that a perceptual hash keeps through resizing.
     *
     * @param positive-int $size
     */
    private function drawImage(string $name, int $size, bool $vertical): string
    {
        $image = imagecreatetruecolor($size, $size);
        $dark = (int) imagecolorallocate($image, 30, 30, 30);
        $light = (int) imagecolorallocate($image, 225, 225, 225);
        $grey = (int) imagecolorallocate($image, 128, 128, 128);

        imagefilledrectangle($image, 0, 0, $size - 1, $size - 1, $light);
        if ($vertical) {
            imagefilledrectangle($image, 0, 0, intdiv($size, 2), $size - 1, $dark);
        } else {
            imagefilledrectangle($image, 0, 0, $size - 1, intdiv($size, 2), $dark);
        }
        imagefilledellipse($image, intdiv($size, 3), intdiv($size * 2, 3), intdiv($size, 3), intdiv($size, 3), $grey);

        imagepng($image, $this->directory . $name);

        return $name;
    }

    private function fingerprint(string $fileName): string
    {
        $collection = new MediaCollection(new MediaStorage(self::makeDb()));
        $media = new Media()->setMediaType('image')->setFileName($fileName);

        return $collection->createFingerprint($media, $this->directory);
    }

    private function distance(string $bits1, string $bits2): int
    {
        return Hash::fromBits($bits1)->distance(Hash::fromBits($bits2));
    }

    public function testFingerprintIsSixtyFourBitsAndStable(): void
    {
        $name = $this->drawImage('a.png', 256, true);

        $bits = $this->fingerprint($name);

        $this->assertMatchesRegularExpression('/^[01]{64}$/', $bits);
        $this->assertSame($bits, $this->fingerprint($name));
    }

    public function testResizedCopyStaysWithinTheDefaultThreshold(): void
    {
        $original = $this->fingerprint($this->drawImage('original.png', 512, true));
        $smaller = $this->fingerprint($this->drawImage('smaller.png', 200, true));

        $this->assertLessThanOrEqual(2, $this->distance($original, $smaller));
    }

    public function testDifferentStructureLandsFarAway(): void
    {
        $vertical = $this->fingerprint($this->drawImage('vertical.png', 256, true));
        $horizontal = $this->fingerprint($this->drawImage('horizontal.png', 256, false));

        $this->assertGreaterThan(16, $this->distance($vertical, $horizontal));
    }

    public function testVideosAreNotFingerprinted(): void
    {
        $collection = new MediaCollection(new MediaStorage(self::makeDb()));
        $video = new Media()->setMediaType('video')->setFileName('clip.mp4');

        $this->assertSame('', $collection->createFingerprint($video, $this->directory));
    }
}
