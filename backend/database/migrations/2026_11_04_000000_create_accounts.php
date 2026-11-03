<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/*
 * Accounts. People sign in with a username and password, and each keeps their
 * own favorite lists, blocked tags and saved searches; an admin is an account
 * with is_admin set. The users table held one placeholder row for the shared
 * admin password's tokens, so it is rebuilt, and those tokens go with it:
 * `php artisan gallery:user <name> --admin` makes the admin account.
 */
return new class extends Migration {
    public function up(): void
    {
        DB::table('personal_access_tokens')->delete();
        Schema::drop('users');

        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('username')->collation('NOCASE')->unique();
            $table->string('password');
            $table->boolean('is_admin')->default(false);
            $table->timestamps();
        });

        // Every account has one default list ("Favorites", what the heart on a
        // card adds to), and any number of its own.
        Schema::create('favorite_lists', function (Blueprint $table): void {
            $table->id('list_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->collation('NOCASE');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });

        Schema::create('favorite_list_items', function (Blueprint $table): void {
            $table->foreignId('list_id')->constrained('favorite_lists', 'list_id')->cascadeOnDelete();
            $table->foreignId('media_id')->constrained('media', 'media_id')->cascadeOnDelete();
            $table->timestamp('added_at')->useCurrent();
            $table->primary(['list_id', 'media_id']);
            $table->index('media_id');
        });

        // Media carrying any of these tags is left out of the account's listings.
        Schema::create('blocked_tags', function (Blueprint $table): void {
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('tag_id')->constrained('tags', 'tag_id')->cascadeOnDelete();
            $table->timestamp('created_at')->useCurrent();
            $table->primary(['user_id', 'tag_id']);
        });

        // A tag search as the URL writes it ("cat,-dog", or "untagged"), by name.
        Schema::create('saved_searches', function (Blueprint $table): void {
            $table->id('search_id');
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('name')->collation('NOCASE');
            $table->string('query', 500);
            $table->timestamps();
            $table->unique(['user_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('saved_searches');
        Schema::dropIfExists('blocked_tags');
        Schema::dropIfExists('favorite_list_items');
        Schema::dropIfExists('favorite_lists');
        DB::table('personal_access_tokens')->delete();
        Schema::drop('users');
        Schema::create('users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }
};
