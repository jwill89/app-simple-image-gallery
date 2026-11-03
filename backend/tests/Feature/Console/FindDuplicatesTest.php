<?php

declare(strict_types=1);

use App\Models\DismissedDuplicate;
use App\Models\Media;
use Illuminate\Support\Facades\File;

// gallery:dupes, the command-line duplicate scan, and its calibration run. The
// scan itself is DuplicateScannerTest's.

const DUPES_BITS = '0110100111010010101100011100101001101001110100101011000111001010';

beforeEach(function (): void {
    $root = sys_get_temp_dir() . '/gallery-dupes-test-' . bin2hex(random_bytes(4));
    config(['gallery.media_path' => "{$root}/media", 'gallery.dupes_path' => "{$root}/dupes"]);
});

afterEach(function (): void {
    File::deleteDirectory(dirname(config()->string('gallery.dupes_path')));
});

test('a scan reports what it found and writes the report the Duplicates page reads', function (): void {
    Media::factory()->count(2)->create(['bits_fingerprint' => DUPES_BITS]);

    command('gallery:dupes')
        ->expectsOutput('Settings: max distance 6 (LSH bands of 9 bits), max region diff 36')
        ->expectsOutput('Images Compared: 2')
        ->expectsOutput('Duplicates Found: 1')
        ->assertSuccessful();

    expect(File::glob(config()->string('gallery.dupes_path') . '/dupes-*.json'))->toHaveCount(1);
});

test('a tuning run keeps dismissed pairs, prints the table, and leaves the report alone', function (): void {
    [$first, $second] = Media::factory()->count(2)->create(['bits_fingerprint' => DUPES_BITS])->all();
    DismissedDuplicate::query()->insert(['media_id_1' => $first->media_id, 'media_id_2' => $second->media_id, 'dismissed_at' => 0]);

    command('gallery:dupes --tune --max-distance=2 --max-region-diff=off')
        ->expectsOutput('Settings: max distance 2 (LSH bands of 21 bits), max region diff off, tuning run')
        ->expectsOutput('Dismissed pairs by distance and region difference:')
        // Distance 0, no thumbnails to compare: the "n/a" column.
        ->expectsOutput(str_pad('0', 10) . str_repeat(str_pad('0', 11), 6) . str_pad('1', 11))
        ->assertSuccessful();

    $dupes = config()->string('gallery.dupes_path');
    expect(File::glob("{$dupes}/dupes-*.json"))->toBe([])
        ->and(File::glob("{$dupes}/tuning-*.json"))->toHaveCount(1);
});

test('an option out of range is refused before anything is scanned', function (string $options, string $message): void {
    command("gallery:dupes {$options}")->expectsOutput($message)->assertExitCode(2);

    expect(File::isDirectory(config()->string('gallery.dupes_path')))->toBeFalse();
})->with([
    'a distance past 16' => ['--max-distance=17', '--max-distance must be a whole number from 0 to 16.'],
    'a region difference past 255' => ['--max-region-diff=300', '--max-region-diff must be a number from 0 to 255, or "off".'],
]);
