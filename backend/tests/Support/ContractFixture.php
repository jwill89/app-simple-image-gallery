<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The contract fixture the Slim app's API was recorded on: the database rows
 * (contract.sql), the upload and thumbnail files, and the recordings
 * themselves (contract/). They live with the recorder in backend-old/ until
 * the migration plan's Phase 8 moves them here.
 */
final class ContractFixture
{
    public static function path(string $relative = ''): string
    {
        return dirname(__DIR__, 3) . '/backend-old/tests/Fixtures' . ($relative === '' ? '' : '/' . $relative);
    }

    /**
     * The production schema as SQL (the Slim app's tests/Support/schema.sql):
     * what the migrations must build and contract.sql is loaded on.
     */
    public static function productionSchema(): string
    {
        return (string) file_get_contents(dirname(__DIR__, 3) . '/backend-old/tests/Support/schema.sql');
    }

    /**
     * A decoded recording, by case id (e.g. "media/upload").
     *
     * @return array<string, mixed>
     */
    public static function recording(string $id): array
    {
        $recording = json_decode((string) file_get_contents(self::path("contract/{$id}.json")), true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($recording)) {
            throw new \UnexpectedValueException("The recording {$id} is not an object.");
        }

        /** @var array<string, mixed> $recording */
        return $recording;
    }
}
