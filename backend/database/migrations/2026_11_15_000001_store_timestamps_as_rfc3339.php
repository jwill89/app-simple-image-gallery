<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Every gallery and account timestamp as RFC 3339 in UTC, to the second
 * (2026-11-07T09:18:09Z), in a DATETIME column whose CHECK refuses any other
 * form: media.file_time and dismissed_duplicates.dismissed_at, converted from
 * Unix seconds, and the account tables' times, converted from Laravel's
 * "Y-m-d H:i:s". SQLite can't change a column's type or add a CHECK in place, so each
 * table is rebuilt by SQLite's documented procedure: foreign keys off outside
 * the transaction; in one transaction, the new table under a new name, the
 * rows copied (their times converted) and counted, the old table dropped and
 * the new one renamed, its indexes made again, AUTOINCREMENT's counter kept,
 * and a foreign key check before the commit.
 *
 * A table that already has its CHECKs is left as it is, so a second run changes
 * nothing.
 */
return new class extends Migration {
    /** The rebuild turns foreign keys off, which SQLite ignores inside a transaction. */
    public $withinTransaction = false;

    private const string FORMAT = '%Y-%m-%dT%H:%M:%SZ';

    public function up(): void
    {
        Schema::withoutForeignKeyConstraints(function (): void {
            DB::transaction(function (): void {
                foreach (self::tables() as $table => $definition) {
                    $this->rebuild($table, ...$definition);
                }

                $violations = DB::select('PRAGMA foreign_key_check');
                if ($violations !== []) {
                    throw new RuntimeException('The rebuilt tables break ' . count($violations) . ' foreign key(s); nothing was changed.');
                }
            });
        });
    }

    /** The old forms aren't written back: the snapshot taken before the deploy holds them. */
    public function down(): never
    {
        throw new RuntimeException('The timestamp rebuild can\'t be undone; restore the pre-deploy snapshot instead.');
    }

    /**
     * Each table: its columns as they are now, but for its timestamps; its
     * indexes; and those timestamp columns.
     *
     * @return array<string, array{0: string, 1: list<string>, 2: list<string>}>
     */
    private static function tables(): array
    {
        $at = static fn(string $column, string $rest = ''): string => "\"{$column}\" DATETIME{$rest} CHECK (\"{$column}\" IS strftime('" . self::FORMAT . "', \"{$column}\"))";
        $now = " NOT NULL DEFAULT (strftime('" . self::FORMAT . "', 'now'))";

        return [
            'media' => [
                '"media_id" integer primary key autoincrement not null, "media_type" text not null default \'image\', "file_name" text not null, '
                . $at('file_time', ' not null')
                . ', "hash" text not null, "bits_fingerprint" text not null default \'\', "width" integer not null default \'0\', "height" integer not null default \'0\', "duration" float not null default \'0\', "file_size" integer not null default \'0\'',
                [
                    'CREATE UNIQUE INDEX "media_file_name_index" on "media" ("file_name")',
                    'CREATE INDEX "media_file_time_media_id_index" on "media" ("file_time", "media_id")',
                    'CREATE INDEX "media_hash_index" on "media" ("hash")',
                    'CREATE INDEX "media_media_type_index" on "media" ("media_type")',
                ],
                ['file_time'],
            ],
            'dismissed_duplicates' => [
                '"media_id_1" integer not null, "media_id_2" integer not null, ' . $at('dismissed_at', ' not null')
                . ', foreign key("media_id_1") references "media"("media_id") on delete cascade, foreign key("media_id_2") references "media"("media_id") on delete cascade, primary key ("media_id_1", "media_id_2")',
                [],
                ['dismissed_at'],
            ],
            'users' => [
                '"id" integer primary key autoincrement not null, "username" varchar not null collate \'NOCASE\', "password" varchar not null, "is_admin" tinyint(1) not null default \'0\', '
                . $at('created_at') . ', ' . $at('updated_at') . ', "remember_token" varchar',
                ['CREATE UNIQUE INDEX "users_username_unique" on "users" ("username")'],
                ['created_at', 'updated_at'],
            ],
            'favorite_lists' => [
                '"list_id" integer primary key autoincrement not null, "user_id" integer not null, "name" varchar not null collate \'NOCASE\', "is_default" tinyint(1) not null default \'0\', '
                . $at('created_at') . ', ' . $at('updated_at')
                . ', foreign key("user_id") references "users"("id") on delete cascade',
                ['CREATE UNIQUE INDEX "favorite_lists_user_id_name_unique" on "favorite_lists" ("user_id", "name")'],
                ['created_at', 'updated_at'],
            ],
            'favorite_list_items' => [
                '"list_id" integer not null, "media_id" integer not null, ' . $at('added_at', $now)
                . ', foreign key("list_id") references "favorite_lists"("list_id") on delete cascade, foreign key("media_id") references "media"("media_id") on delete cascade, primary key ("list_id", "media_id")',
                ['CREATE INDEX "favorite_list_items_media_id_index" on "favorite_list_items" ("media_id")'],
                ['added_at'],
            ],
            'blocked_tags' => [
                '"user_id" integer not null, "tag_id" integer not null, ' . $at('created_at', $now)
                . ', foreign key("user_id") references "users"("id") on delete cascade, foreign key("tag_id") references "tags"("tag_id") on delete cascade, primary key ("user_id", "tag_id")',
                [],
                ['created_at'],
            ],
            'saved_searches' => [
                '"search_id" integer primary key autoincrement not null, "user_id" integer not null, "name" varchar not null collate \'NOCASE\', "query" varchar not null, '
                . $at('created_at') . ', ' . $at('updated_at')
                . ', foreign key("user_id") references "users"("id") on delete cascade',
                ['CREATE UNIQUE INDEX "saved_searches_user_id_name_unique" on "saved_searches" ("user_id", "name")'],
                ['created_at', 'updated_at'],
            ],
            'passkeys' => [
                '"id" integer primary key autoincrement not null, "user_id" integer not null, "name" varchar not null, "credential_id" varchar not null, "credential" text not null, '
                . $at('last_used_at') . ', ' . $at('created_at') . ', ' . $at('updated_at')
                . ', foreign key("user_id") references "users"("id") on delete cascade',
                [
                    'CREATE UNIQUE INDEX "passkeys_credential_id_unique" on "passkeys" ("credential_id")',
                    'CREATE INDEX "passkeys_user_id_index" on "passkeys" ("user_id")',
                ],
                ['last_used_at', 'created_at', 'updated_at'],
            ],
        ];
    }

    /**
     * @param list<string> $indexes
     * @param list<string> $times
     */
    private function rebuild(string $table, string $columns, array $indexes, array $times): void
    {
        $sql = (string) DB::scalar("SELECT sql FROM sqlite_master WHERE type = 'table' AND name = ?", [$table]);
        if (str_contains($sql, "IS strftime('" . self::FORMAT . "'")) {
            return;
        }

        $new = "{$table}_rebuilt";
        DB::statement("CREATE TABLE \"{$new}\" ({$columns})");

        // Unix seconds (the gallery's tables) or SQLite's "Y-m-d H:i:s" text
        // (Laravel's), both read as UTC; a value already in the stored form
        // passes through.
        $select = [];
        foreach (array_column(DB::select("PRAGMA table_info(\"{$new}\")"), 'name') as $column) {
            $name = (string) $column;
            $select[$name] = in_array($name, $times, true)
                ? "CASE WHEN typeof(\"{$name}\") IN ('integer', 'real') THEN strftime('" . self::FORMAT . "', \"{$name}\", 'unixepoch') ELSE strftime('" . self::FORMAT . "', \"{$name}\") END"
                : "\"{$name}\"";
        }
        $list = implode(', ', array_map(static fn(string $name): string => "\"{$name}\"", array_keys($select)));
        DB::statement("INSERT INTO \"{$new}\" ({$list}) SELECT " . implode(', ', $select) . " FROM \"{$table}\"");

        // Every row, and every time that was set, came across.
        $counts = static fn(string $from): string => json_encode(DB::selectOne(
            'SELECT COUNT(*) AS "rows"' . implode('', array_map(static fn(string $time): string => ", COUNT(\"{$time}\") AS \"{$time}\"", $times)) . " FROM \"{$from}\"",
        ), JSON_THROW_ON_ERROR);
        if ($counts($new) !== $counts($table)) {
            throw new RuntimeException("Rebuilding {$table} would lose rows or times ({$counts($table)} became {$counts($new)}); nothing was changed.");
        }

        // AUTOINCREMENT's counter goes with the old table: the new one's starts
        // from its largest id, which would hand out again the id of a row
        // deleted from the end.
        $sequence = DB::scalar('SELECT seq FROM sqlite_sequence WHERE name = ?', [$table]);

        DB::statement("DROP TABLE \"{$table}\"");
        DB::statement("ALTER TABLE \"{$new}\" RENAME TO \"{$table}\"");
        foreach ($indexes as $index) {
            DB::statement($index);
        }
        if (is_numeric($sequence) && DB::update('UPDATE sqlite_sequence SET seq = MAX(seq, ?) WHERE name = ?', [(int) $sequence, $table]) === 0) {
            DB::insert('INSERT INTO sqlite_sequence (name, seq) VALUES (?, ?)', [$table, (int) $sequence]);
        }
    }
};
