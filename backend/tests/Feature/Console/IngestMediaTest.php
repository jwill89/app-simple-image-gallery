<?php

declare(strict_types=1);

use App\Jobs\ImportDanbooruTags;
use App\Jobs\ProcessNewMedia;
use App\Models\Media;
use Illuminate\Process\PendingProcess;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Queue;
use Tests\Support\ContractFixture;

// The nightly ingest: files in the media folder's root are added and moved into
// full/, copies and taken names are deleted, other types stay, and items whose
// file is gone are removed. The queue runs jobs inline here
// (QUEUE_CONNECTION=sync), so each run ingests at once.

/**
 * The ffmpeg arguments that write a thumbnail.
 *
 * @return list<string>
 */
function thumbnailCommand(string $source, string $filter, string $output): array
{
    return ['ffmpeg', '-i', $source, '-vf', $filter, '-frames:v', '1', '-c:v', 'libwebp', '-q:v', '80', '-y', $output];
}

function scale(int $size): string
{
    return "scale='min({$size},iw)':'min({$size},ih)':flags=lanczos:force_original_aspect_ratio=decrease";
}

/** Drops a fixture upload into the ingest folder as $name, last modified at $time. */
function dropIn(string $fixture, string $name, int $time = 1767225600): string
{
    $path = config()->string('gallery.media_path') . '/' . $name;
    File::copy(ContractFixture::path("uploads/{$fixture}"), $path);
    touch($path, $time);

    return $path;
}

beforeEach(function (): void {
    $root = sys_get_temp_dir() . '/gallery-ingest-test-' . bin2hex(random_bytes(4));
    config(['gallery.media_path' => "{$root}/media"]);
    File::ensureDirectoryExists("{$root}/media/full");
    // No ffmpeg or ffprobe, as on a host without them.
    Process::fake(['*' => Process::result(errorOutput: 'command not found', exitCode: 127)]);
});

afterEach(function (): void {
    File::deleteDirectory(dirname(config()->string('gallery.media_path')));
});

test('new files are added with their type, time, hash, fingerprint and metadata, and moved into full/', function (): void {
    dropIn('tiny.png', 'tiny.png', 1767225600);
    dropIn('static.gif', 'still.gif', 1767229200);
    dropIn('animated.gif', 'moving.gif', 1767232800);

    command('gallery:ingest')->expectsOutput('Queued 3 new file(s); left 0 of other types in place.')->assertSuccessful();

    $named = static fn(string $name): Media => Media::query()->where('file_name', $name)->firstOrFail();

    // Added in name order.
    expect(Media::query()->orderBy('media_id')->pluck('file_name')->all())->toBe(['moving.gif', 'still.gif', 'tiny.png'])
        ->and($named('tiny.png')->only(['media_type', 'file_time', 'hash', 'bits_fingerprint', 'width', 'height', 'duration', 'file_size']))->toBe([
            'media_type' => 'image',
            'file_time' => 1767225600,
            'hash' => md5_file(ContractFixture::path('uploads/tiny.png')),
            // The fingerprint the contract recording media/upload holds for the same file.
            'bits_fingerprint' => '1101111110010111011110010111100101111101011101110110011101110111',
            'width' => 40,
            'height' => 56,
            'duration' => 0.0,
            'file_size' => 726,
        ])
        ->and($named('still.gif')->media_type)->toBe('image')
        // An animated GIF is a video: no fingerprint, and without ffprobe its
        // size comes from getimagesize().
        ->and($named('moving.gif')->only(['media_type', 'bits_fingerprint', 'width', 'height']))->toBe(['media_type' => 'video', 'bits_fingerprint' => '', 'width' => 1, 'height' => 1]);

    $folder = config()->string('gallery.media_path');
    expect(File::files($folder))->toBe([])
        ->and(File::isFile("{$folder}/full/tiny.png"))->toBeTrue()
        ->and(File::isFile("{$folder}/full/moving.gif"))->toBeTrue();
});

test('a file whose name or content is in the gallery is deleted, and other types are left alone', function (): void {
    $folder = config()->string('gallery.media_path');
    Media::factory()->create(['file_name' => 'taken.png', 'hash' => 'not-its-hash']);
    File::put("{$folder}/full/taken.png", 'the item that has the name');
    Media::factory()->create(['file_name' => 'original.jpg', 'hash' => md5_file(ContractFixture::path('uploads/tiny.jpg'))]);
    File::put("{$folder}/full/original.jpg", 'the item that has the content');

    dropIn('tiny.png', 'taken.png');
    dropIn('tiny.jpg', 'copy.jpg');
    dropIn('notes.txt', 'notes.txt');

    command('gallery:ingest')->expectsOutput('Queued 2 new file(s); left 1 of other types in place.')->assertSuccessful();

    expect(Media::query()->count())->toBe(2)
        ->and(array_map(static fn(SplFileInfo $file): string => $file->getFilename(), File::files($folder)))->toBe(['notes.txt'])
        ->and(File::get("{$folder}/full/taken.png"))->toBe('the item that has the name');
});

test('an item whose file is gone is removed, with its thumbnails', function (): void {
    $folder = config()->string('gallery.media_path');
    $kept = Media::factory()->create(['file_name' => 'kept.jpg']);
    File::put("{$folder}/full/kept.jpg", 'still here');
    $gone = Media::factory()->create(['file_name' => 'gone.jpg']);
    File::ensureDirectoryExists("{$folder}/thumbs");
    File::put("{$folder}/thumbs/gone.webp", 'thumbnail');
    File::put("{$folder}/thumbs/gone@2x.webp", 'thumbnail');

    command('gallery:ingest')->expectsOutput('Removed 1 item(s) whose files are gone.')->assertSuccessful();

    expect(Media::query()->pluck('media_id')->all())->toBe([$kept->media_id])
        ->and($gone->tags()->count())->toBe(0)
        ->and(File::files("{$folder}/thumbs"))->toBe([]);
});

test('nothing is removed when the media path looks wrong', function (string $problem): void {
    $folder = config()->string('gallery.media_path');
    Media::factory()->count(2)->create();
    if ($problem === 'no full/ folder') {
        File::deleteDirectory("{$folder}/full");
    }
    dropIn('tiny.png', 'still.png');

    command('gallery:ingest')->assertFailed();

    expect(Media::query()->count())->toBe(2)
        ->and(File::isFile("{$folder}/still.png"))->toBeTrue();
})->with(['no full/ folder', 'no item\'s file in full/']);

test('thumbnails and video metadata come from ffmpeg and ffprobe, called as cron.php called them', function (): void {
    $folder = config()->string('gallery.media_path');
    // ffmpeg writes what it is asked to; ffprobe describes a 1080p clip.
    Process::fake(static function (PendingProcess $process): Illuminate\Contracts\Process\ProcessResult {
        $command = (array) $process->command;
        if ($command[0] === 'ffprobe') {
            return Process::result((string) json_encode(['streams' => [['width' => 1920, 'height' => 1080]], 'format' => ['duration' => '12.345']]));
        }
        File::put(end($command), 'webp');

        return Process::result();
    });
    dropIn('tiny.png', 'still.png');
    File::put("{$folder}/clip.mp4", 'not really a video; nothing here reads it but ffmpeg');

    command('gallery:ingest')->assertSuccessful();

    $still = "{$folder}/still.png";
    Process::assertRan(static fn(PendingProcess $process): bool => $process->command === thumbnailCommand($still, scale(200), "{$folder}/thumbs/still.webp"));
    Process::assertRan(static fn(PendingProcess $process): bool => $process->command === thumbnailCommand($still, scale(400), "{$folder}/thumbs/still@2x.webp"));
    // A video's frame is chosen by the thumbnail filter; it worked, so no first-frame retry.
    Process::assertRan(static fn(PendingProcess $process): bool => $process->command === thumbnailCommand("{$folder}/clip.mp4", 'thumbnail,' . scale(200), "{$folder}/thumbs/clip.webp"));
    Process::assertNotRan(static fn(PendingProcess $process): bool => $process->command === thumbnailCommand("{$folder}/clip.mp4", scale(200), "{$folder}/thumbs/clip.webp"));

    expect(Media::query()->where('file_name', 'clip.mp4')->firstOrFail()->only(['media_type', 'width', 'height', 'duration']))
        ->toBe(['media_type' => 'video', 'width' => 1920, 'height' => 1080, 'duration' => 12.35]);
});

test('when the thumbnail filter fails on a video, its first frame is used', function (): void {
    $folder = config()->string('gallery.media_path');
    Process::fake(static function (PendingProcess $process): Illuminate\Contracts\Process\ProcessResult {
        $command = (array) $process->command;
        if (str_starts_with((string) ($command[4] ?? ''), 'thumbnail,')) {
            return Process::result(errorOutput: 'too short', exitCode: 1);
        }
        File::put((string) end($command), 'webp');

        return Process::result();
    });
    File::put("{$folder}/short.mp4", 'a clip too short for the thumbnail filter');

    command('gallery:ingest')->assertSuccessful();

    Process::assertRan(static fn(PendingProcess $process): bool => $process->command === thumbnailCommand("{$folder}/short.mp4", scale(400), "{$folder}/thumbs/short@2x.webp"));
    expect(File::isFile("{$folder}/thumbs/short@2x.webp"))->toBeTrue();
});

test('--import-tags queues a Danbooru import for each new image, not for videos', function (): void {
    config(['gallery.danbooru.login' => 'someone', 'gallery.danbooru.api_key' => 'key']);
    Queue::fake([ImportDanbooruTags::class]);
    dropIn('tiny.png', 'still.png');
    dropIn('animated.gif', 'moving.gif');

    command('gallery:ingest --import-tags')->assertSuccessful();

    Queue::assertPushed(ImportDanbooruTags::class, 1);
    Queue::assertPushed(ImportDanbooruTags::class, static fn(ImportDanbooruTags $job): bool => $job->media->file_name === 'still.png');
});

test('--import-tags without Danbooru credentials fails before ingesting anything', function (): void {
    config(['gallery.danbooru.login' => '', 'gallery.danbooru.api_key' => '']);
    dropIn('tiny.png', 'still.png');

    command('gallery:ingest --import-tags')->assertFailed();

    expect(Media::query()->count())->toBe(0);
});

test('a file still waiting from an earlier run is not queued again', function (): void {
    Queue::fake();
    dropIn('tiny.png', 'still.png');

    command('gallery:ingest')->assertSuccessful();
    command('gallery:ingest')->assertSuccessful();

    Queue::assertPushed(ProcessNewMedia::class, 1);
});
