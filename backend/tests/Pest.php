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

/** The gallery's own page, whose writes the tests' config allows. */
const GALLERY_ORIGIN = 'https://gallery.test';

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
 * The test, about to send requests from the gallery's own page, signed out:
 * with its Origin, which the config here allows, and these headers. The auth
 * guard keeps the account it found for the next request, and withHeaders()
 * adds to the headers every later request in the test sends, so a test that
 * changes who is asking starts afresh here.
 *
 * @param array<string, string> $headers
 */
function fromGallery(array $headers = []): LaravelTestCase
{
    config(['gallery.allowed_origins' => [GALLERY_ORIGIN], 'cors.allowed_origins' => [GALLERY_ORIGIN]]);
    app('auth')->forgetGuards();
    flushHeaders();

    return withHeaders(['Origin' => GALLERY_ORIGIN, ...$headers]);
}

/**
 * The test, about to send requests from the gallery's own page as a signed-in
 * account: a new ordinary one unless given. (Signing in itself, and its
 * cookies, are tested with a Tests\Support\TestBrowser.)
 */
function asAccount(?User $user = null): LaravelTestCase
{
    return fromGallery()->actingAs($user ?? User::factory()->create());
}

/** The test, about to send requests as the signed-in admin. */
function asAdmin(): LaravelTestCase
{
    return asAccount(adminUser());
}

/** The test, about to send requests as no one: no session, no Origin. */
function signedOut(): LaravelTestCase
{
    app('auth')->forgetGuards();
    flushHeaders();

    return withHeaders([]);
}
