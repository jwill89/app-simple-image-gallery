<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Support\ContractFixture;

// A fresh migrate builds the production schema, and migrating the live database
// adds only Laravel's tables.

/** The tables the Laravel app adds: Laravel's own, and the accounts' (2026_11_04_000000_create_accounts and after). */
const LARAVEL_TABLES = [
    'failed_jobs', 'job_batches', 'jobs', 'migrations', 'users',
    'blocked_tags', 'favorite_list_items', 'favorite_lists', 'passkeys', 'saved_searches',
];

/** The production tables the migrations don't create (see the baseline migration). */
const REPLACED_TABLES = ['auth_tokens', 'rate_limits'];

function productionSchemaDb(): PDO
{
    $db = new PDO('sqlite::memory:');
    $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db->exec(ContractFixture::productionSchema());

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
 * What a table's behavior depends on: each column's affinity, nullability,
 * default, key part and collation; AUTOINCREMENT; each index's columns, order,
 * collations and uniqueness; and each foreign key with its actions. Declared
 * type names and quoting are left out: SQLite treats VARCHAR(50) and varchar,
 * or a default of 0 and '0' in an INTEGER column, alike.
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
        'indexes' => $indexes,
        'foreign_keys' => $foreignKeys,
    ];
}

test('a fresh migrate builds every production table as production has it', function (): void {
    $production = productionSchemaDb();
    $migrated = DB::connection()->getPdo();
    $galleryTables = array_values(array_diff(tablesOf($production), REPLACED_TABLES));

    expect(tablesOf($migrated))->toEqualCanonicalizing([...$galleryTables, ...LARAVEL_TABLES]);

    foreach ($galleryTables as $table) {
        expect(describeTable($migrated, $table))->toBe(describeTable($production, $table), "The {$table} table differs from production.");
    }
});

test('migrating a database the Slim app built only adds Laravel\'s tables', function (): void {
    // Production's state: the schema, Phinx's bookkeeping, and data.
    $path = sys_get_temp_dir() . '/gallery-existing-' . bin2hex(random_bytes(4)) . '.db';
    $existing = new PDO('sqlite:' . $path);
    $existing->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $existing->exec(ContractFixture::productionSchema());
    $existing->exec('CREATE TABLE phinx_migrations (version BIGINT NOT NULL, migration_name VARCHAR(100) NULL, start_time TIMESTAMP NULL, end_time TIMESTAMP NULL, breakpoint BOOLEAN NOT NULL DEFAULT 0, PRIMARY KEY (version))');
    $existing->exec((string) file_get_contents(ContractFixture::path('contract.sql')));

    $snapshot = static function (PDO $db): array {
        $objects = $db->query("SELECT type || ' ' || name, sql FROM sqlite_master WHERE name <> 'sqlite_sequence'");
        $schema = $objects === false ? [] : $objects->fetchAll(PDO::FETCH_KEY_PAIR);
        $rows = [];
        foreach (tablesOf($db) as $table) {
            $rows[$table] = rowCount($db, $table);
        }

        return ['schema' => $schema, 'rows' => $rows];
    };
    $before = $snapshot($existing);
    unset($existing);

    config(['database.connections.existing' => ['database' => $path] + config()->array('database.connections.sqlite')]);
    try {
        expect(Artisan::call('migrate', ['--database' => 'existing', '--force' => true]))->toBe(0);
        $after = $snapshot(DB::connection('existing')->getPdo());
    } finally {
        DB::purge('existing');
        foreach (['', '-wal', '-shm'] as $suffix) {
            if (is_file($path . $suffix)) {
                unlink($path . $suffix);
            }
        }
    }

    $added = array_diff_key($after['rows'], $before['rows']);
    expect(array_keys($added))->toEqualCanonicalizing(LARAVEL_TABLES)
        ->and(array_intersect_key($after['schema'], $before['schema']))->toBe($before['schema'])
        ->and(array_intersect_key($after['rows'], $before['rows']))->toBe($before['rows']);
});
