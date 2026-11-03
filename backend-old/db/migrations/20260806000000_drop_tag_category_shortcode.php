<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

/**
 * Drop `tag_categories.category_short`.
 *
 * The shortcode existed for one feature: typing `a:artist name` when adding a
 * tag, so the prefix picked the category. That was parsed by
 * `TagRepository::getOrCreate()`, which had no callers — every live path that
 * creates a tag sets the category explicitly instead:
 *
 *   - TagController::createTag()  — category comes from the form's dropdown
 *   - DanbooruTagger              — category comes from danbooru_category_map,
 *                                   inserted straight into `tags (name, cat)`
 *
 * So the column, its unique indexes, the API validation and the help text all
 * described something the application could not actually do.
 *
 * `down()` restores the column and its index but cannot restore the old values
 * — they were single letters derived from the category names, so re-deriving
 * the first initial is as close as it gets.
 */
final class DropTagCategoryShortcode extends AbstractMigration
{
    public function up(): void
    {
        // Both indexes cover the column being dropped: the original unique index
        // from the initial schema, and the NOCASE one added later.
        $this->execute('DROP INDEX IF EXISTS idx_tag_categories_short_nocase');
        $this->execute('DROP INDEX IF EXISTS tag_categories_category_short_index');

        $this->table('tag_categories')
            ->removeColumn('category_short')
            ->update();
    }

    public function down(): void
    {
        $this->table('tag_categories')
            ->addColumn('category_short', 'text', ['null' => false, 'default' => '', 'after' => 'category_name'])
            ->update();

        // Best-effort: the original single-letter codes are gone, so seed from
        // the first letter of each category name and let conflicts sort
        // themselves out before the unique index goes back on.
        $this->execute("UPDATE tag_categories SET category_short = lower(substr(category_name, 1, 1))");
        $this->execute(
            'CREATE UNIQUE INDEX IF NOT EXISTS idx_tag_categories_short_nocase'
            . ' ON tag_categories (category_short COLLATE NOCASE)'
        );
    }
}
