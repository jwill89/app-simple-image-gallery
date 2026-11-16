<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/*
 * The tables the Slim app left behind, which nothing reads: its login tokens
 * (auth_tokens), its rate limits (rate_limits, kept in the cache now) and
 * Phinx's migration log. Only a database the Slim app built has them.
 */
return new class extends Migration {
    public function up(): void
    {
        foreach (['auth_tokens', 'rate_limits', 'phinx_migrations'] as $table) {
            Schema::dropIfExists($table);
        }
    }

    /** The dropped tables don't come back: the snapshot taken before the deploy holds them. */
    public function down(): never
    {
        throw new RuntimeException('Dropping the Slim tables can\'t be undone; restore the pre-deploy snapshot instead.');
    }
};
