<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\ContractFixture;

// A fresh migrate builds the committed schema (tests/Fixtures/schema.sql,
// `php bin/schema.php` writes it), and migrating the live database, which the
// Slim app built, ends at the same schema with every row and time kept.

/** The Slim app's tables that a migration drops: nothing reads them. */
const SLIM_TABLES = ['auth_tokens', 'rate_limits', 'phinx_migrations'];

/** The migration that rebuilds the timestamp tables, and the one before it. */
const TIMESTAMP_MIGRATION = '2026_11_15_000001_store_timestamps_as_rfc3339';

function committedSchemaDb(): PDO
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec(ContractFixture::schema());

    return $db;
}

/** @return list<string> */
function tablesOf(PDO $db): array
{
    $tables = $db->query("SELECT name FROM sqlite_master WHERE type = 'table' AND name <> 'sqlite_sequence' ORDER BY name");

    return $tables === false ? [] : array_values(array_map(strval(...), $tables->fetchAll(PDO::FETCH_COLUMN)));
}

/** @return list<array<mixed>> */
function pragmaRows(PDO $db, string $pragma): array
{
    $rows = $db->query("PRAGMA {$pragma}");

    return $rows === false ? [] : array_values(array_filter($rows->fetchAll(PDO::FETCH_ASSOC), is_array(...)));
}

function rowCount(PDO $db, string $table): int
{
    $count = $db->query("SELECT COUNT(*) FROM \"{$table}\"");

    return $count === false ? 0 : (int) $count->fetchColumn();
}

function scalar(PDO $db, string $sql): mixed
{
    $result = $db->query($sql);

    return $result === false ? null : $result->fetchColumn();
}

/** SQLite's type affinity for a declared column type (the rules in sqlite.org/datatype3.html). */
function affinity(string $type): string
{
    $type = strtoupper($type);

    return match (true) {
        str_contains($type, 'INT') => 'INTEGER',
        str_contains($type, 'CHAR'), str_contains($type, 'CLOB'), str_contains($type, 'TEXT') => 'TEXT',
        $type === '', str_contains($type, 'BLOB') => 'BLOB',
        str_contains($type, 'REAL'), str_contains($type, 'FLOA'), str_contains($type, 'DOUB') => 'REAL',
        default => 'NUMERIC',
    };
}

/**
 * Each CHECK constraint's expression in a table's SQL, in order, quotes and
 * spaces normalized.
 *
 * @return list<string>
 */
function checksOf(string $sql): array
{
    $checks = [];
    $offset = 0;
    while (preg_match('/\bcheck\s*\(/i', $sql, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
        $start = $match[0][1] + strlen($match[0][0]);
        $end = $start;
        $depth = 1;
        while ($end < strlen($sql) && $depth > 0) {
            $depth += match ($sql[$end]) {
                '(' => 1,
                ')' => -1,
                default => 0,
            };
            $end++;
        }
        $checks[] = (string) preg_replace('/\s+/', ' ', str_replace(['"', '`'], '', substr($sql, $start, $end - $start - 1)));
        $offset = $end;
    }

    return $checks;
}

/**
 * What a table's behavior depends on: each column's affinity, nullability,
 * default, key part and collation; AUTOINCREMENT; its CHECK constraints; each
 * index's columns, order, collations and uniqueness; and each foreign key with
 * its actions. Declared type names and quoting are left out: SQLite treats
 * VARCHAR(50) and varchar, or a default of 0 and '0' in an INTEGER column,
 * alike.
 *
 * @return array<string, mixed>
 */
function describeTable(PDO $db, string $table): array
{
    $sqlQuery = $db->prepare("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?");
    $sqlQuery->execute([$table]);
    $sql = (string) $sqlQuery->fetchColumn();

    $columns = [];
    foreach (pragmaRows($db, "table_xinfo(\"{$table}\")") as $column) {
        $name = (string) $column['name'];
        $default = $column['dflt_value'];
        $collation = preg_match('/[\s(,][`"]?' . preg_quote($name, '/') . '[`"]?\s[^,]*?\bcollate\s+[\'"`]?(\w+)/i', $sql, $match) === 1
            ? strtoupper($match[1])
            : 'BINARY';
        $columns[] = [
            'name' => $name,
            'affinity' => affinity((string) $column['type']),
            'notnull' => (int) $column['notnull'],
            'default' => is_string($default) ? trim($default, "'") : null,
            'pk' => (int) $column['pk'],
            'collation' => $collation,
        ];
    }

    $indexes = [];
    foreach (pragmaRows($db, "index_list(\"{$table}\")") as $index) {
        $name = (string) $index['name'];
        $keys = array_values(array_filter(pragmaRows($db, "index_xinfo(\"{$name}\")"), static fn(array $key): bool => (int) $key['key'] === 1));
        $indexes[$name] = [
            'unique' => (int) $index['unique'],
            'columns' => array_map(static fn(array $key): string => $key['name'] . ' ' . $key['coll'] . ((int) $key['desc'] === 1 ? ' DESC' : ''), $keys),
        ];
    }
    ksort($indexes);

    $foreignKeys = array_map(
        static fn(array $key): string => "{$key['from']} -> {$key['table']}.{$key['to']} on update {$key['on_update']} on delete {$key['on_delete']}",
        pragmaRows($db, "foreign_key_list(\"{$table}\")"),
    );
    sort($foreignKeys);

    return [
        'columns' => $columns,
        'autoincrement' => stripos($sql, 'autoincrement') !== false,
        'checks' => checksOf($sql),
        'indexes' => $indexes,
        'foreign_keys' => $foreignKeys,
    ];
}

/** @return array<string, array<string, mixed>> */
function describeSchema(PDO $db): array
{
    $tables = [];
    foreach (tablesOf($db) as $table) {
        $tables[$table] = describeTable($db, $table);
    }

    return $tables;
}

test('a fresh migrate builds the committed schema', function (): void {
    $committed = committedSchemaDb();
    $migrated = DB::connection()->getPdo();

    expect(tablesOf($migrated))->toBe(tablesOf($committed));
    foreach (tablesOf($committed) as $table) {
        expect(describeTable($migrated, $table))->toBe(describeTable($committed, $table), "The {$table} table differs from schema.sql (php bin/schema.php writes it).");
    }
});

test('every timestamp column holds RFC 3339 in UTC to the second, and refuses any other form', function (string $value): void {
    expect(static fn() => DB::table('media')->insert(['media_type' => 'image', 'file_name' => 'x.png', 'file_time' => $value, 'hash' => 'h']))
        ->toThrow(QueryException::class, 'CHECK constraint failed');
})->with([
    'SQLite\'s space form' => ['2026-11-07 09:18:09'],
    'an offset' => ['2026-11-07T09:18:09+00:00'],
    'fractional seconds' => ['2026-11-07T09:18:09.401Z'],
    'an impossible date' => ['2026-02-30T09:18:09Z'],
    'Unix seconds' => ['1767225600'],
]);

test('migrating the live database converts its times, drops the Slim tables and ends at the committed schema', function (): void {
    // The live database as the Slim app left it: its schema, Phinx's bookkeeping,
    // the gallery's rows (Unix-second times), and an AUTOINCREMENT counter past
    // the last id, as deleting the newest item leaves it.
    $path = sys_get_temp_dir() . '/gallery-existing-' . bin2hex(random_bytes(4)) . '.db';
    $existing = new PDO('sqlite:' . $path);
    $existing->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $existing->exec(ContractFixture::slimSchema());
    $existing->exec('CREATE TABLE phinx_migrations (version BIGINT NOT NULL, migration_name VARCHAR(100) NULL, start_time TIMESTAMP NULL, end_time TIMESTAMP NULL, breakpoint BOOLEAN NOT NULL DEFAULT 0, PRIMARY KEY (version))');
    $existing->exec((string) file_get_contents(ContractFixture::path('contract.sql')));
    $existing->exec("INSERT INTO media (media_id, media_type, file_name, file_time, hash) VALUES (31, 'image', 'legacy.png', 1767225600, 'legacy')");
    $existing->exec('INSERT INTO dismissed_duplicates (media_id_1, media_id_2, dismissed_at) VALUES (14, 31, 1767900000)');
    $existing->exec("UPDATE sqlite_sequence SET seq = 40 WHERE name = 'media'");
    $galleryRows = [];
    foreach (array_diff(tablesOf($existing), SLIM_TABLES) as $table) {
        $galleryRows[$table] = rowCount($existing, $table);
    }
    unset($existing);

    config(['database.connections.existing' => ['database' => $path] + config()->array('database.connections.sqlite')]);
    try {
        // Every migration before the rebuild, then an account's rows as Laravel
        // wrote them until now, then the rest.
        $migrations = glob(database_path('migrations/*.php'));
        $before = array_values(array_filter(
            $migrations === false ? [] : $migrations,
            static fn(string $file): bool => basename($file, '.php') < '2026_11_15',
        ));
        expect(Artisan::call('migrate', ['--database' => 'existing', '--path' => $before, '--realpath' => true, '--force' => true]))->toBe(0);
        $db = DB::connection('existing')->getPdo();
        $db->exec("INSERT INTO users (id, username, password, is_admin, created_at, updated_at) VALUES (1, 'old', 'x', 0, '2026-11-05 10:00:00', '2026-11-06 11:30:15')");
        $db->exec("INSERT INTO favorite_lists (list_id, user_id, name, is_default, created_at, updated_at) VALUES (1, 1, 'Favorites', 1, '2026-11-05 10:00:00', NULL)");
        $db->exec("INSERT INTO favorite_list_items (list_id, media_id, added_at) VALUES (1, 3, '2026-11-07 09:18:09')");
        $db->exec("INSERT INTO blocked_tags (user_id, tag_id, created_at) VALUES (1, 4, '2026-11-08 00:00:00')");
        $db->exec("INSERT INTO saved_searches (search_id, user_id, name, query, created_at, updated_at) VALUES (1, 1, 'Cats', 'cat', '2026-11-09 12:00:00', '2026-11-09 12:00:00')");
        $db->exec("INSERT INTO passkeys (id, user_id, name, credential_id, credential, last_used_at, created_at, updated_at) VALUES (1, 1, 'Phone', 'cred', '{}', NULL, '2026-11-10 08:00:00', '2026-11-10 08:00:00')");

        expect(Artisan::call('migrate', ['--database' => 'existing', '--force' => true]))->toBe(0);
        $migrated = describeSchema($db);
        $times = [
            'legacy media' => scalar($db, 'SELECT file_time FROM media WHERE media_id = 31'),
            'fixture media' => scalar($db, 'SELECT file_time FROM media WHERE media_id = 1'),
            'legacy dismissal' => scalar($db, 'SELECT dismissed_at FROM dismissed_duplicates WHERE media_id_2 = 31'),
            'fixture dismissal' => scalar($db, 'SELECT dismissed_at FROM dismissed_duplicates WHERE media_id_1 = 12'),
            'account' => scalar($db, 'SELECT created_at || \' \' || updated_at FROM users WHERE id = 1'),
            'list' => scalar($db, 'SELECT created_at || \' \' || IFNULL(updated_at, \'null\') FROM favorite_lists'),
            'list item' => scalar($db, 'SELECT added_at FROM favorite_list_items'),
            'blocked tag' => scalar($db, 'SELECT created_at FROM blocked_tags'),
            'search' => scalar($db, 'SELECT created_at FROM saved_searches'),
            'passkey' => scalar($db, 'SELECT created_at || \' \' || IFNULL(last_used_at, \'null\') FROM passkeys'),
        ];
        $rows = [];
        foreach (array_keys($galleryRows) as $table) {
            $rows[$table] = rowCount($db, $table);
        }
        $sequence = scalar($db, "SELECT seq FROM sqlite_sequence WHERE name = 'media'");
        $violations = pragmaRows($db, 'foreign_key_check');
    } finally {
        $db = null;
        DB::purge('existing');
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($path . $suffix)) {
                unlink($path . $suffix);
            }
        }
    }

    expect($migrated)->toBe(describeSchema(committedSchemaDb()))
        ->and(array_intersect(array_keys($migrated), SLIM_TABLES))->toBe([])
        ->and($rows)->toBe($galleryRows)
        ->and((int) $sequence)->toBe(40)
        ->and($violations)->toBe([])
        ->and($times)->toBe([
            'legacy media' => '2026-01-01T00:00:00Z',
            'fixture media' => '2026-01-01T00:00:00Z',
            'legacy dismissal' => '2026-01-08T19:20:00Z',
            'fixture dismissal' => '2026-01-08T19:20:00Z',
            'account' => '2026-11-05T10:00:00Z 2026-11-06T11:30:15Z',
            'list' => '2026-11-05T10:00:00Z null',
            'list item' => '2026-11-07T09:18:09Z',
            'blocked tag' => '2026-11-08T00:00:00Z',
            'search' => '2026-11-09T12:00:00Z',
            'passkey' => '2026-11-10T08:00:00Z null',
        ]);
});

test('the timestamp rebuild run again changes nothing', function (): void {
    $db = DB::connection()->getPdo();
    $schema = describeSchema($db);

    $migration = require database_path('migrations/' . TIMESTAMP_MIGRATION . '.php');
    $migration->up();

    expect(describeSchema($db))->toBe($schema);
});

test('the dropped tables and the old times don\'t come back', function (string $migration): void {
    expect(static fn() => (require database_path("migrations/{$migration}.php"))->down())
        ->toThrow(RuntimeException::class, 'restore the pre-deploy snapshot');
})->with(['2026_11_15_000000_drop_slim_tables', TIMESTAMP_MIGRATION]);
