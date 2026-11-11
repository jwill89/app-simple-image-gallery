<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * The baseline: the gallery's tables as production has them, each created only
 * where it is missing. On a fresh database this builds the schema; on the live
 * one, which already holds these tables, it does nothing. Columns, keys,
 * collations and index names follow tests/Fixtures/schema.sql, the production
 * schema, which tests/Feature/Database/SchemaTest.php compares the result
 * against.
 *
 * No table is created for logins or rate limits: sessions are files and rate
 * limits live in the cache. A live database may still hold leftover
 * auth_tokens and rate_limits tables, which nothing reads.
 */
return new class extends Migration {
    public function up(): void
    {
        if (!Schema::hasTable('tag_categories')) {
            Schema::create('tag_categories', function (Blueprint $table): void {
                $table->increments('category_id');
                $table->text('category_name')->collation('NOCASE');
                $table->string('color', 20)->nullable()->default('neutral');
                $table->text('description')->nullable()->default('');
                $table->integer('sort_order')->nullable()->default(0);

                $table->unique('category_name', 'tag_categories_category_name_index');
            });
            DB::statement('CREATE UNIQUE INDEX idx_tag_categories_name_nocase ON tag_categories (category_name COLLATE NOCASE)');
        }

        if (!Schema::hasTable('tags')) {
            Schema::create('tags', function (Blueprint $table): void {
                $table->increments('tag_id');
                $table->integer('category_id')->default(1);
                $table->text('tag_name')->collation('NOCASE');

                $table->foreign('category_id')->references('category_id')->on('tag_categories');
                $table->index('category_id', 'tags_category_id_index');
                $table->unique('tag_name', 'tags_tag_name_index');
            });
            DB::statement('CREATE UNIQUE INDEX idx_tags_name_nocase ON tags (tag_name COLLATE NOCASE)');
        }

        if (!Schema::hasTable('media')) {
            Schema::create('media', function (Blueprint $table): void {
                $table->increments('media_id');
                $table->text('media_type')->default('image');
                $table->text('file_name');
                $table->integer('file_time');
                $table->text('hash');
                $table->text('bits_fingerprint')->default('');
                $table->integer('width')->default(0);
                $table->integer('height')->default(0);
                $table->float('duration')->default(0);
                $table->integer('file_size')->default(0);

                $table->unique('file_name', 'media_file_name_index');
                $table->index(['file_time', 'media_id'], 'media_file_time_media_id_index');
                $table->index('hash', 'media_hash_index');
                $table->index('media_type', 'media_media_type_index');
            });
        }

        if (!Schema::hasTable('media_tags')) {
            Schema::create('media_tags', function (Blueprint $table): void {
                $table->integer('media_id');
                $table->integer('tag_id');

                $table->primary(['media_id', 'tag_id']);
                $table->foreign('media_id')->references('media_id')->on('media')->cascadeOnDelete()->cascadeOnUpdate();
                $table->foreign('tag_id')->references('tag_id')->on('tags')->cascadeOnDelete()->cascadeOnUpdate();
                $table->index(['tag_id', 'media_id'], 'media_tags_tag_id_media_id_index');
            });
        }

        if (!Schema::hasTable('tag_implications')) {
            Schema::create('tag_implications', function (Blueprint $table): void {
                $table->integer('tag_id');
                $table->integer('implied_tag_id');

                $table->primary(['tag_id', 'implied_tag_id']);
                $table->foreign('tag_id')->references('tag_id')->on('tags')->cascadeOnDelete();
                $table->foreign('implied_tag_id')->references('tag_id')->on('tags')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('danbooru_category_map')) {
            Schema::create('danbooru_category_map', function (Blueprint $table): void {
                $table->integer('danbooru_category_id');
                $table->string('danbooru_category_name', 50);
                $table->integer('gallery_category_id');

                $table->primary('danbooru_category_id');
                $table->foreign('gallery_category_id')->references('category_id')->on('tag_categories')->cascadeOnDelete();
            });
        }

        if (!Schema::hasTable('danbooru_tag_map')) {
            Schema::create('danbooru_tag_map', function (Blueprint $table): void {
                $table->increments('id');
                $table->string('danbooru_tag', 200);
                $table->string('gallery_tag', 200);

                $table->unique('danbooru_tag', 'danbooru_tag_map_danbooru_tag_index');
            });
        }

        if (!Schema::hasTable('dismissed_duplicates')) {
            Schema::create('dismissed_duplicates', function (Blueprint $table): void {
                $table->integer('media_id_1');
                $table->integer('media_id_2');
                $table->integer('dismissed_at');

                $table->primary(['media_id_1', 'media_id_2']);
                $table->foreign('media_id_1')->references('media_id')->on('media')->cascadeOnDelete();
                $table->foreign('media_id_2')->references('media_id')->on('media')->cascadeOnDelete();
            });
        }
    }

    /**
     * Drops nothing. On production these tables hold the gallery and were not
     * created here, so rolling the baseline back must not touch them.
     */
    public function down(): void {}
};
