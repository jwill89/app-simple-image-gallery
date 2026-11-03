<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddCategoryColorsAndDanbooruRules extends AbstractMigration
{
    public function up(): void
    {
        // Add color, description, and sort_order columns to tag_categories
        $this->table('tag_categories')
            ->addColumn('color', 'string', ['limit' => 20, 'default' => 'white', 'after' => 'category_short'])
            ->addColumn('description', 'text', ['default' => '', 'after' => 'color'])
            ->addColumn('sort_order', 'integer', ['default' => 0, 'after' => 'description'])
            ->update();

        // Seed default colors, descriptions, and sort orders for existing categories
        $this->execute("UPDATE tag_categories SET color = 'white',   description = 'General terms that describe features.', sort_order = 0 WHERE category_name = 'General'");
        $this->execute("UPDATE tag_categories SET color = 'danger',  description = 'The artist who created this specific piece.', sort_order = 1 WHERE category_name = 'Artist'");
        $this->execute("UPDATE tag_categories SET color = 'success', description = 'Character name.', sort_order = 2 WHERE category_name = 'Character'");
        $this->execute("UPDATE tag_categories SET color = 'warning', description = 'Source material (movie, game, anime, etc.).', sort_order = 3 WHERE category_name = 'Source'");
        $this->execute("UPDATE tag_categories SET color = 'info',    description = 'Personal lists for individuals to record favorites.', sort_order = 4 WHERE category_name = 'Personal List'");

        // Danbooru category map: maps Danbooru category IDs to gallery category IDs
        $this->table('danbooru_category_map', ['id' => false, 'primary_key' => ['danbooru_category_id']])
            ->addColumn('danbooru_category_id', 'integer', ['null' => false])
            ->addColumn('danbooru_category_name', 'string', ['limit' => 50, 'null' => false])
            ->addColumn('gallery_category_id', 'integer', ['null' => false])
            ->addForeignKey('gallery_category_id', 'tag_categories', 'category_id', ['delete' => 'CASCADE'])
            ->create();

        // Seed default Danbooru category mappings (matching previous CATEGORY_MAP constant)
        $this->execute("INSERT INTO danbooru_category_map (danbooru_category_id, danbooru_category_name, gallery_category_id) VALUES (0, 'General', 1)");
        $this->execute("INSERT INTO danbooru_category_map (danbooru_category_id, danbooru_category_name, gallery_category_id) VALUES (1, 'Artist', 2)");
        $this->execute("INSERT INTO danbooru_category_map (danbooru_category_id, danbooru_category_name, gallery_category_id) VALUES (3, 'Copyright', 4)");
        $this->execute("INSERT INTO danbooru_category_map (danbooru_category_id, danbooru_category_name, gallery_category_id) VALUES (4, 'Character', 3)");

        // Danbooru tag name map: renames certain Danbooru tags to gallery names
        $this->table('danbooru_tag_map')
            ->addColumn('danbooru_tag', 'string', ['limit' => 200, 'null' => false])
            ->addColumn('gallery_tag', 'string', ['limit' => 200, 'null' => false])
            ->addIndex(['danbooru_tag'], ['unique' => true])
            ->create();

        // Seed default tag name mappings (matching previous TAG_NAME_MAP constant)
        $this->execute("INSERT INTO danbooru_tag_map (danbooru_tag, gallery_tag) VALUES ('1boy', 'one man')");
        $this->execute("INSERT INTO danbooru_tag_map (danbooru_tag, gallery_tag) VALUES ('1girl', 'one woman')");
        $this->execute("INSERT INTO danbooru_tag_map (danbooru_tag, gallery_tag) VALUES ('multiple_boys', 'multiple men')");
        $this->execute("INSERT INTO danbooru_tag_map (danbooru_tag, gallery_tag) VALUES ('multiple_girls', 'multiple women')");
        $this->execute("INSERT INTO danbooru_tag_map (danbooru_tag, gallery_tag) VALUES ('2boys', 'two men')");
        $this->execute("INSERT INTO danbooru_tag_map (danbooru_tag, gallery_tag) VALUES ('2girls', 'two women')");
    }

    public function down(): void
    {
        $this->table('danbooru_tag_map')->drop()->save();
        $this->table('danbooru_category_map')->drop()->save();

        $this->table('tag_categories')
            ->removeColumn('color')
            ->removeColumn('description')
            ->removeColumn('sort_order')
            ->update();
    }
}
