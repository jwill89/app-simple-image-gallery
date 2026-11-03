<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Sessions instead of bearer tokens. A browser signs in with a session cookie
 * the page's scripts can't read, so the tokens it kept in storage go, and the
 * table with them. "Keep me signed in" is Laravel's remember cookie, which
 * names the account's remember_token: a new password replaces it, and every
 * remember cookie with it.
 */
return new class extends Migration {
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table): void {
            $table->rememberToken()->after('password');
        });

        Schema::dropIfExists('personal_access_tokens');
    }

    public function down(): void
    {
        Schema::create('personal_access_tokens', function (Blueprint $table): void {
            $table->id();
            $table->morphs('tokenable');
            $table->text('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamps();
        });

        Schema::table('users', function (Blueprint $table): void {
            $table->dropColumn('remember_token');
        });
    }
};
