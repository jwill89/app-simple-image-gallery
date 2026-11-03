<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

use function Pest\Laravel\artisan;

// Feature tests run inside the booted application, on a freshly migrated
// in-memory database; unit tests need neither.
pest()->extend(TestCase::class)->use(RefreshDatabase::class)->in('Feature');

/**
 * An Artisan command to set expectations on. artisan() is typed to return an
 * exit code as well, which it does only when console output isn't mocked, as
 * it always is in these tests.
 */
function command(string $command): PendingCommand
{
    $pending = artisan($command);

    return $pending instanceof PendingCommand ? $pending : throw new LogicException('Console output is not mocked.');
}

/**
 * The headers of a write by the signed-in admin: an allowed Origin (allowed
 * here) and a fresh token, as login issues one.
 *
 * @return array<string, string>
 */
function adminHeaders(): array
{
    config(['gallery.allowed_origins' => ['https://gallery.test'], 'cors.allowed_origins' => ['https://gallery.test']]);
    $token = bin2hex(random_bytes(32));
    User::admin()->tokens()->create(['name' => 'admin', 'token' => hash('sha256', $token), 'abilities' => ['*']]);

    return ['Origin' => 'https://gallery.test', 'Authorization' => "Bearer {$token}"];
}
