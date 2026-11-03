<?php

// CLI-only guard
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'This script can only be run from the command line.';
    exit(1);
}

/**
 * Database setup / bootstrap.
 *
 * Phinx migrations (db/migrations/) are the SINGLE SOURCE OF TRUTH for the
 * schema, indexes, and seed data. This script only ensures the SQLite database
 * file exists, then applies all pending migrations.
 *
 * Equivalent manual commands:
 *     php vendor/bin/phinx migrate              # apply migrations
 *     php vendor/bin/phinx create MyMigration   # scaffold a new migration
 *
 * Do NOT add hand-written CREATE TABLE statements here — add a migration instead.
 */

// Resolve to project root so phinx.php's relative paths (db/gallery.db,
// db/migrations) line up regardless of where this is invoked from.
chdir(__DIR__ . '/..');

require __DIR__ . '/../vendor/autoload.php';

use Phinx\Console\PhinxApplication;
use Symfony\Component\Console\Input\StringInput;
use Symfony\Component\Console\Output\ConsoleOutput;

echo "Gallery - Database Setup (Phinx migrations)\n";
echo "===========================================\n\n";

// Ensure the SQLite database file exists. PDO/Phinx would create it on connect,
// but creating it up front surfaces permission problems with a clearer message.
$dbPath = __DIR__ . '/gallery.db';

if (!file_exists($dbPath)) {
    if (@touch($dbPath) === false) {
        echo "[ERROR] Could not create database file at db/gallery.db. Check directory permissions.\n";
        exit(1);
    }
    echo "[OK] Created database file: db/gallery.db\n";
} else {
    echo "[OK] Database file exists: db/gallery.db\n";
}

if (!is_writable($dbPath)) {
    echo "[ERROR] Database file is not writable. Please check the permissions.\n";
    exit(1);
}

// Adopt a legacy database created by the pre-Phinx hand-written setup, so
// `phinx migrate` doesn't try to re-create tables that already exist.
baselineLegacyDatabase($dbPath);

echo "\nApplying migrations...\n\n";

$phinx = new PhinxApplication();
$phinx->setAutoExit(false);

$exitCode = $phinx->run(
    new StringInput('migrate -c phinx.php'),
    new ConsoleOutput()
);

echo "\n";
echo $exitCode === 0
    ? "Setup complete.\n"
    : "[ERROR] Migrations failed (exit code {$exitCode}).\n";

exit($exitCode);

/**
 * One-time legacy adoption.
 *
 * The production database was originally created by the old hand-written
 * setup.php (no Phinx tracking). If we detect that state — an existing schema
 * with no `phinx_migrations` table — we record the migrations whose changes are
 * already present, so Phinx applies only genuinely new migrations instead of
 * failing on `CREATE TABLE` for tables that already exist.
 *
 * This is idempotent: once `phinx_migrations` exists (normal Phinx-managed DBs,
 * including fresh local ones), it returns immediately. Brand-new empty databases
 * are also left untouched so Phinx can build them from scratch.
 */
function baselineLegacyDatabase(string $dbPath): void
{
    $pdo = new PDO('sqlite:' . $dbPath);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

    $tableExists = static function (PDO $pdo, string $name): bool {
        $stmt = $pdo->prepare("SELECT 1 FROM sqlite_master WHERE type = 'table' AND name = :n");
        $stmt->execute([':n' => $name]);
        return (bool) $stmt->fetchColumn();
    };

    // Already Phinx-managed → nothing to do.
    if ($tableExists($pdo, 'phinx_migrations')) {
        return;
    }

    // Brand-new/empty DB (no core table) → let Phinx create everything.
    if (!$tableExists($pdo, 'media')) {
        return;
    }

    echo "[baseline] Legacy database detected (no phinx_migrations table).\n";
    echo "[baseline] Recording already-applied migrations...\n";

    $columnExists = static fn(PDO $pdo, string $table, string $column): bool => array_any(
        $pdo->query('PRAGMA table_info(' . $table . ')')->fetchAll(PDO::FETCH_ASSOC),
        fn(array $row): bool => $row['name'] === $column
    );

    // version => [migration class name, "already present?" probe]
    // Only migrations that existed at adoption time need probes; anything newer
    // stays pending and is applied normally by the migrate step below.
    $migrations = [
        '20260517000000' => ['InitialSchema', static fn() => $tableExists($pdo, 'media')],
        '20260522000000' => ['AddTagImplicationsAndIndexes', static fn() => $tableExists($pdo, 'tag_implications')],
        '20260523000000' => ['AddCategoryColorsAndDanbooruRules', static fn() => $columnExists($pdo, 'tag_categories', 'color')],
        '20260620000000' => ['AddMediaMetadata', static fn() => $columnExists($pdo, 'media', 'width')],
    ];

    // Create Phinx's tracking table (matches Phinx's SQLite schema so it can
    // insert subsequent migration rows itself).
    $pdo->exec(
        'CREATE TABLE phinx_migrations (
            version BIGINT NOT NULL,
            migration_name VARCHAR(100) NULL,
            start_time TIMESTAMP NULL,
            end_time TIMESTAMP NULL,
            breakpoint BOOLEAN NOT NULL DEFAULT 0,
            PRIMARY KEY (version)
        )'
    );

    $now = date('Y-m-d H:i:s');
    $insert = $pdo->prepare(
        'INSERT INTO phinx_migrations (version, migration_name, start_time, end_time, breakpoint)
         VALUES (:v, :n, :s, :e, 0)'
    );

    foreach ($migrations as $version => [$name, $isPresent]) {
        if ($isPresent()) {
            $insert->execute([':v' => $version, ':n' => $name, ':s' => $now, ':e' => $now]);
            echo "  marked applied: {$version} {$name}\n";
        }
    }
}
