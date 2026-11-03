<?php

declare(strict_types=1);

use App\Services\Fingerprinter;
use Intervention\ImageHash\Hash;
use Tests\Support\ContractFixture;

// Fingerprinting on images drawn with GD, and on files the Slim app already
// fingerprinted: old and new fingerprints are compared with each other, so a
// change in how they are computed would silently break the duplicate scan.

/**
 * The fingerprint of a $size-pixel square split into a dark and a light half,
 * vertically or horizontally, with a mid-grey disc off-centre: low-frequency
 * structure that a perceptual hash keeps through resizing.
 *
 * @param positive-int $size
 */
function fingerprintOfDrawing(int $size, bool $vertical): string
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

    $path = sys_get_temp_dir() . '/gallery-fingerprint-' . bin2hex(random_bytes(4)) . '.png';
    imagepng($image, $path);

    try {
        return new Fingerprinter()->fingerprint($path);
    } finally {
        unlink($path);
    }
}

function fingerprintDistance(string $bits1, string $bits2): int
{
    return Hash::fromBits($bits1)->distance(Hash::fromBits($bits2));
}

test('a fingerprint is sixty-four bits and stable', function (): void {
    $bits = fingerprintOfDrawing(256, true);

    expect($bits)->toMatch('/^[01]{64}$/')
        ->and(fingerprintOfDrawing(256, true))->toBe($bits);
});

test('a resized copy stays within the default threshold', function (): void {
    expect(fingerprintDistance(fingerprintOfDrawing(512, true), fingerprintOfDrawing(200, true)))->toBeLessThanOrEqual(2);
});

test('different structure lands far away', function (): void {
    expect(fingerprintDistance(fingerprintOfDrawing(256, true), fingerprintOfDrawing(256, false)))->toBeGreaterThan(16);
});

test('a file fingerprints as the Slim app stored it', function (string $file, string $case, int $mediaId): void {
    // Every JSON value the case's responses carry, lists flattened, then the media item.
    /** @var list<array{response: array{json?: mixed}}> $steps */
    $steps = ContractFixture::recording($case)['steps'];
    $stored = collect($steps)
        ->flatMap(static function (array $step): array {
            $json = $step['response']['json'] ?? null;

            return is_array($json) && array_is_list($json) ? $json : [$json];
        })
        ->firstOrFail(static fn(mixed $media): bool => is_array($media) && ($media['media_id'] ?? null) === $mediaId);

    expect(new Fingerprinter()->fingerprint(ContractFixture::path($file)))->toBe($stored['bits_fingerprint']);
})->with([
    'PNG' => ['uploads/tiny.png', 'media/upload', 31],
    'JPEG' => ['uploads/tiny.jpg', 'media/upload-mixed', 31],
    'static GIF' => ['uploads/static.gif', 'media/upload-mixed', 32],
]);
