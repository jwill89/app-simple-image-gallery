<?php

declare(strict_types=1);

use App\Jobs\RebuildFromFile;
use App\Models\Media;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ContractFixture;

// gallery:regenerate rebuilds what is derived from each item's file in
// media/full/.

/**
 * A media item for the fixture upload $fixture, stored in media/full/ under its own name.
 *
 * @param array<string, mixed> $attributes
 */
function storedMedia(string $fixture, array $attributes = []): Media
{
    File::copy(ContractFixture::path("uploads/{$fixture}"), config()->string('gallery.media_path') . "/full/{$fixture}");

    return Media::factory()->create(['file_name' => $fixture, 'media_type' => 'image', ...$attributes]);
}

beforeEach(function (): void {
    $root = sys_get_temp_dir() . '/gallery-regenerate-test-' . bin2hex(random_bytes(4));
    config(['gallery.media_path' => "{$root}/media"]);
    File::ensureDirectoryExists("{$root}/media/full");
    Process::fake(['*' => Process::result(errorOutput: 'command not found', exitCode: 127)]);
});

afterEach(function (): void {
    File::deleteDirectory(dirname(config()->string('gallery.media_path')));
});

test('metadata is read again from the file', function (): void {
    $media = storedMedia('tiny.png', ['width' => 1, 'height' => 1, 'file_size' => 1]);

    command('gallery:regenerate metadata')->expectsOutput('Queued 1 item(s) to have their metadata rebuilt.')->assertSuccessful();

    expect($media->refresh()->only(['width', 'height', 'duration', 'file_size']))->toBe(['width' => 40, 'height' => 56, 'duration' => 0.0, 'file_size' => 726]);
});

test('fingerprints are computed again for images, and cleared where they can\'t be', function (): void {
    $image = storedMedia('tiny.png', ['bits_fingerprint' => str_repeat('0', 64)]);
    $missing = Media::factory()->create(['media_type' => 'image', 'bits_fingerprint' => str_repeat('1', 64)]);
    $unreadable = storedMedia('not-an-image.png', ['bits_fingerprint' => str_repeat('1', 64)]);
    $video = Media::factory()->video()->create(['bits_fingerprint' => '']);

    command('gallery:regenerate fingerprints')->expectsOutput('Queued 3 item(s) to have their fingerprints rebuilt.')->assertSuccessful();

    expect($image->refresh()->bits_fingerprint)->toBe('1101111110010111011110010111100101111101011101110110011101110111')
        ->and($missing->refresh()->bits_fingerprint)->toBe('')
        ->and($unreadable->refresh()->bits_fingerprint)->toBe('')
        ->and($video->refresh()->bits_fingerprint)->toBe('');
});

test('thumbnails are made again, and leftovers in an older format are removed first', function (): void {
    $folder = config()->string('gallery.media_path');
    storedMedia('tiny.png');
    File::ensureDirectoryExists("{$folder}/thumbs");
    File::put("{$folder}/thumbs/old.jpg", 'a thumbnail from before WebP');
    File::put("{$folder}/thumbs/tiny.webp", 'the current one');

    command('gallery:regenerate thumbnails')->expectsOutput('Removed 1 old thumbnail file(s).')->assertSuccessful();

    expect(array_map(static fn(SplFileInfo $file): string => $file->getFilename(), File::files("{$folder}/thumbs")))->toBe(['tiny.webp']);
    Process::assertRan(static function (PendingProcess $process) use ($folder): bool {
        $command = (array) $process->command;

        return $command[2] === "{$folder}/full/tiny.png" && end($command) === "{$folder}/thumbs/tiny@2x.webp";
    });
});

test('an item whose file is missing is skipped for thumbnails and metadata', function (): void {
    $media = Media::factory()->create(['width' => 7]);

    command('gallery:regenerate metadata')->assertSuccessful();
    command('gallery:regenerate thumbnails')->assertSuccessful();

    expect($media->refresh()->width)->toBe(7);
    Process::assertNothingRan();
});

test('each item is a job of its own, and an unknown derivative is refused', function (): void {
    Queue::fake();
    Media::factory()->count(2)->create();
    Media::factory()->video()->create();

    command('gallery:regenerate thumbnails')->assertSuccessful();
    Queue::assertPushed(RebuildFromFile::class, 3);

    command('gallery:regenerate everything')->expectsOutput('Say what to rebuild: thumbnails, fingerprints or metadata.')->assertExitCode(2);
});
