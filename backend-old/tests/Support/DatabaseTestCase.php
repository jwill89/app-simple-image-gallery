<?php

declare(strict_types=1);

namespace Gallery\Tests\Support;

use PDO;
use PHPUnit\Framework\TestCase;

/**
 * Base test case for tests that exercise real SQL.
 *
 * Each call to makeDb() returns an isolated in-memory SQLite database with the
 * full application schema (tests/Support/schema.sql, generated from the Phinx
 * migrations) loaded, so Storage/Collection logic runs against the real schema
 * without touching the on-disk database.
 */
abstract class DatabaseTestCase extends TestCase
{
    protected static function makeDb(): PDO
    {
        $pdo = new PDO('sqlite::memory:');
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $schema = file_get_contents(__DIR__ . '/schema.sql');
        if ($schema === false) {
            self::fail('Could not read tests/Support/schema.sql — regenerate it (see CONTRIBUTING.md).');
        }

        $pdo->exec($schema);
        $pdo->exec('PRAGMA foreign_keys = ON');

        return $pdo;
    }
}
