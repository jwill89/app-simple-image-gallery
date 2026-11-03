<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as LaravelTestCase;
use Illuminate\Testing\PendingCommand;
use Tests\TestCase;

use function Pest\Laravel\artisan;
use function Pest\Laravel\flushHeaders;
use function Pest\Laravel\withHeaders;

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

/** The admin account, made the first time a test asks for it. */
function adminUser(): User
{
    return User::query()->where('username', 'admin')->first() ?? User::factory()->admin()->create(['username' => 'admin']);
}

/**
 * The headers of a write by a signed-in account (a new ordinary one unless
 * given): an allowed Origin (allowed here) and a fresh token, as login issues
 * one.
 *
 * @return array<string, string>
 */
function accountHeaders(?User $user = null): array
{
    config(['gallery.allowed_origins' => ['https://gallery.test'], 'cors.allowed_origins' => ['https://gallery.test']]);
    $user ??= User::factory()->create();

    return ['Origin' => 'https://gallery.test', 'Authorization' => 'Bearer ' . $user->issueToken()];
}

/**
 * The headers of a write by the signed-in admin.
 *
 * @return array<string, string>
 */
function adminHeaders(): array
{
    return accountHeaders(adminUser());
}

/**
 * The test, about to send requests with exactly these headers. withHeaders()
 * adds to the headers every later request in the test sends, and the auth
 * guard keeps the account it found for the next request, so a test that
 * changes who is asking starts afresh here.
 *
 * @param array<string, string> $headers
 */
function asAccount(array $headers): LaravelTestCase
{
    app('auth')->forgetGuards();
    flushHeaders();

    return withHeaders($headers);
}

/** The test, about to send requests as no one: no token, no Origin. */
function signedOut(): LaravelTestCase
{
    return asAccount([]);
}
