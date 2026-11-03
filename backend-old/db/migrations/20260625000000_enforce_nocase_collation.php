<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Enforce case-insensitive uniqueness on tag and category names.
 *
 * The original hand-written (pre-Phinx) schema declared these columns
 * `COLLATE NOCASE`, but the InitialSchema migration did not, so databases
 * built purely from migrations get binary-collated unique indexes. That lets
 * "Foo"/"foo" coexist as distinct tags and breaks the case-insensitive
 * de-duplication that the Danbooru importer relies on.
 *
 * This adds explicit NOCASE unique indexes to close that gap. On the legacy /
 * production database — where the columns are already `COLLATE NOCASE` — the
 * per-table guard makes this a no-op, so existing deployments are untouched.
 */
final class EnforceNocaseCollation extends AbstractMigration
{
    public function up(): void
    {
        $this->addNocaseUniqueIndex('tags', 'tag_name', 'idx_tags_name_nocase');
        $this->addNocaseUniqueIndex('tag_categories', 'category_name', 'idx_tag_categories_name_nocase');
        $this->addNocaseUniqueIndex('tag_categories', 'category_short', 'idx_tag_categories_short_nocase');
    }

    public function down(): void
    {
        $this->execute('DROP INDEX IF EXISTS idx_tags_name_nocase');
        $this->execute('DROP INDEX IF EXISTS idx_tag_categories_name_nocase');
        $this->execute('DROP INDEX IF EXISTS idx_tag_categories_short_nocase');
    }

    /**
     * Add a unique NOCASE index, unless the table already declares its columns
     * COLLATE NOCASE (in which case uniqueness is already case-insensitive).
     */
    private function addNocaseUniqueIndex(string $table, string $column, string $indexName): void
    {
        $row = $this->fetchRow(
            "SELECT sql FROM sqlite_master WHERE type = 'table' AND name = '" . $table . "'"
        );
        $tableSql = $row['sql'] ?? '';

        if (stripos($tableSql, 'COLLATE NOCASE') !== false) {
            // Legacy/production schema already enforces case-insensitive
            // uniqueness at the column level — nothing to do.
            return;
        }

        $this->execute(sprintf(
            'CREATE UNIQUE INDEX IF NOT EXISTS %s ON %s (%s COLLATE NOCASE)',
            $indexName,
            $table,
            $column
        ));
    }
}
