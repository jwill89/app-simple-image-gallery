-- Auto-generated from Phinx migrations (backend/db/migrations), then hand-adjusted
-- to mirror the deployed (production) schema. This is a flattened snapshot used to
-- build a fresh in-memory SQLite database for each test. Regenerate when the schema
-- changes (see CONTRIBUTING.md), preserving the two manual edits below:
--   1. tags.tag_name and tag_categories.category_name are COLLATE
--      NOCASE — the legacy/production columns are case-insensitive, but the migrations
--      only add NOCASE *indexes* (see 20260625000000_enforce_nocase_collation.php),
--      so a raw migration dump would be case-sensitive at the column level.
--   2. the phinxlog migration-tracking table is omitted (tests don't need it).

CREATE TABLE `auth_tokens` (`token` TEXT NOT NULL, `created_at` INTEGER NOT NULL, PRIMARY KEY (`token`));
CREATE TABLE "danbooru_category_map" (`danbooru_category_id` INTEGER NOT NULL, `danbooru_category_name` VARCHAR(50) NOT NULL, `gallery_category_id` INTEGER NOT NULL, PRIMARY KEY (`danbooru_category_id`), FOREIGN KEY (`gallery_category_id`) REFERENCES `tag_categories` (`category_id`) ON DELETE CASCADE);
CREATE TABLE `danbooru_tag_map` (`id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, `danbooru_tag` VARCHAR(200) NOT NULL, `gallery_tag` VARCHAR(200) NOT NULL);
CREATE TABLE "dismissed_duplicates" (`media_id_1` INTEGER NOT NULL, `media_id_2` INTEGER NOT NULL, `dismissed_at` INTEGER NOT NULL, PRIMARY KEY (`media_id_1`,`media_id_2`), FOREIGN KEY (`media_id_1`) REFERENCES `media` (`media_id`) ON DELETE CASCADE, FOREIGN KEY (`media_id_2`) REFERENCES `media` (`media_id`) ON DELETE CASCADE);
CREATE TABLE "media" (`media_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, `media_type` TEXT NOT NULL DEFAULT 'image', `file_name` TEXT NOT NULL, `file_time` INTEGER NOT NULL, `hash` TEXT NOT NULL, `bits_fingerprint` TEXT NOT NULL DEFAULT '', `width` INTEGER NOT NULL DEFAULT 0, `height` INTEGER NOT NULL DEFAULT 0, `duration` FLOAT NOT NULL DEFAULT 0, `file_size` INTEGER NOT NULL DEFAULT 0);
CREATE TABLE "media_tags" (`media_id` INTEGER NOT NULL, `tag_id` INTEGER NOT NULL, PRIMARY KEY (`media_id`,`tag_id`), FOREIGN KEY (`media_id`) REFERENCES `media` (`media_id`) ON DELETE CASCADE ON UPDATE CASCADE, FOREIGN KEY (`tag_id`) REFERENCES `tags` (`tag_id`) ON DELETE CASCADE ON UPDATE CASCADE);
CREATE TABLE `rate_limits` (`ip` TEXT NOT NULL, `requested_at` INTEGER NOT NULL);
CREATE TABLE "tag_categories" (`category_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, `category_name` TEXT NOT NULL COLLATE NOCASE, `color` VARCHAR(20) NULL DEFAULT 'neutral', `description` TEXT NULL DEFAULT '', `sort_order` INTEGER NULL DEFAULT 0);
CREATE TABLE "tag_implications" (`tag_id` INTEGER NOT NULL, `implied_tag_id` INTEGER NOT NULL, PRIMARY KEY (`tag_id`,`implied_tag_id`), FOREIGN KEY (`tag_id`) REFERENCES `tags` (`tag_id`) ON DELETE CASCADE, FOREIGN KEY (`implied_tag_id`) REFERENCES `tags` (`tag_id`) ON DELETE CASCADE);
CREATE TABLE "tags" (`tag_id` INTEGER NOT NULL PRIMARY KEY AUTOINCREMENT, `category_id` INTEGER NOT NULL DEFAULT 1, `tag_name` TEXT NOT NULL COLLATE NOCASE, FOREIGN KEY (`category_id`) REFERENCES `tag_categories` (`category_id`));
CREATE INDEX `auth_tokens_created_at_index` ON `auth_tokens` (`created_at` ASC);
CREATE UNIQUE INDEX `danbooru_tag_map_danbooru_tag_index` ON `danbooru_tag_map` (`danbooru_tag` ASC);
CREATE UNIQUE INDEX idx_tag_categories_name_nocase ON tag_categories (category_name COLLATE NOCASE);
CREATE UNIQUE INDEX idx_tags_name_nocase ON tags (tag_name COLLATE NOCASE);
CREATE UNIQUE INDEX `media_file_name_index` ON `media` (`file_name` ASC);
CREATE INDEX `media_file_time_media_id_index` ON `media` (`file_time` ASC,`media_id` ASC);
CREATE INDEX `media_hash_index` ON `media` (`hash` ASC);
CREATE INDEX `media_media_type_index` ON `media` (`media_type` ASC);
CREATE INDEX `media_tags_tag_id_media_id_index` ON "media_tags" (`tag_id` ASC,`media_id` ASC);
CREATE INDEX `rate_limits_ip_requested_at_index` ON `rate_limits` (`ip` ASC,`requested_at` ASC);
CREATE UNIQUE INDEX `tag_categories_category_name_index` ON `tag_categories` (`category_name` ASC);
CREATE INDEX `tags_category_id_index` ON "tags" (`category_id` ASC);
CREATE UNIQUE INDEX `tags_tag_name_index` ON "tags" (`tag_name` ASC);
