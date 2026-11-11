<?php

declare(strict_types=1);

namespace Tests\Support;

/**
 * The contract fixture the API was recorded on (tests/Fixtures/):
 * the database rows (contract.sql), the upload and thumbnail files, and the
 * recordings themselves (contract/, format in its README).
 */
final class ContractFixture
{
    public static function path(string $relative = ''): string
    {
        return dirname(__DIR__) . '/Fixtures' . ($relative === '' ? '' : '/' . $relative);
    }

    /**
     * The production schema as SQL (schema.sql): what the migrations must build
     * and contract.sql is loaded on.
     */
    public static function productionSchema(): string
    {
        return (string) file_get_contents(self::path('schema.sql'));
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
