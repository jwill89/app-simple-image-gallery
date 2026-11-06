<?php

declare(strict_types=1);

use App\Models\FavoriteList;
use App\Models\Tag;
use App\Models\TagCategory;
use App\Models\User;
use Tests\Support\TestBrowser;

use function Pest\Laravel\travel;

// Accounts: sign-up and login by username and password (the auth/ recordings
// divergences.php supersedes point here), the session that keeps a browser
// signed in, the admin role, an account's own password and deletion, and the
// admin's Users page.

beforeEach(function (): void {
    config(['gallery.allow_signup' => true]);
});

/** A browser signed in as $username, kept signed in with $remember. */
function signedInBrowser(string $username, bool $remember = false, string $password = 'long-enough'): TestBrowser
{
    $browser = new TestBrowser();
    $browser->send('POST', '/api/auth/login', ['username' => $username, 'password' => $password, 'remember' => $remember])->assertOk();

    return $browser;
}

test('signing up makes an ordinary account with a Favorites list, and signs the browser in', function (): void {
    $browser = new TestBrowser();
    $browser->send('POST', '/api/auth/register', ['username' => 'Reader', 'password' => 'long-enough', 'is_admin' => true])
        ->assertCreated()
        ->assertJsonPath('user.username', 'Reader')
        ->assertJsonPath('user.is_admin', false)
        ->assertJsonMissingPath('token');

    $browser->send('GET', '/api/auth/session')->assertOk()->assertJsonPath('user.username', 'Reader');
    expect(FavoriteList::query()->where('is_default', true)->value('name'))->toBe('Favorites');
});

test('the session is a cookie the page\'s scripts can\'t read, gone when the browser closes', function (): void {
    User::factory()->create(['username' => 'reader', 'password' => 'long-enough']);
    $cookie = new TestBrowser()->send('POST', '/api/auth/login', ['username' => 'reader', 'password' => 'long-enough'])
        ->assertOk()
        ->getCookie(config()->string('session.cookie'), decrypt: false);

    expect($cookie)->not->toBeNull()
        ->and($cookie?->isHttpOnly())->toBeTrue()
        ->and($cookie?->getSameSite())->toBe('lax')
        ->and($cookie?->getExpiresTime())->toBe(0);
});

test('signed out, the session says so', function (): void {
    new TestBrowser()->send('GET', '/api/auth/session')->assertOk()->assertExactJson(['user' => null]);
});

test('a username is checked, and taken whatever its letter case', function (): void {
    User::factory()->create(['username' => 'reader']);
    $register = static fn(array $body) => fromGallery()->postJson('/api/auth/register', $body);

    $register(['username' => 'READER', 'password' => 'long-enough'])->assertStatus(409)->assertJson(['error' => 'UsernameTaken']);
    $register(['username' => 'no spaces', 'password' => 'long-enough'])->assertStatus(400)->assertJson(['error' => 'InvalidUsername']);
    $register(['username' => 'ab', 'password' => 'long-enough'])->assertStatus(400)->assertJson(['error' => 'InvalidUsername']);
    $register(['username' => 'fine', 'password' => 'short'])->assertStatus(400)
        ->assertExactJson(['error' => 'WeakPassword', 'message' => 'A password is at least 8 characters.', 'errors' => ['password' => ['A password is at least 8 characters.']]]);
});

test('sign-up can be closed, and is limited to five accounts an hour per address', function (): void {
    config(['gallery.allow_signup' => false]);
    fromGallery()->postJson('/api/auth/register', ['username' => 'closed', 'password' => 'long-enough'])
        ->assertStatus(403)->assertJson(['error' => 'SignupClosed']);

    config(['gallery.allow_signup' => true]);
    foreach (range(1, 5) as $n) {
        fromGallery()->postJson('/api/auth/register', ['username' => "reader{$n}", 'password' => 'long-enough'])->assertCreated();
    }
    fromGallery()->postJson('/api/auth/register', ['username' => 'reader6', 'password' => 'long-enough'])
        ->assertStatus(429)->assertJson(['error' => 'TooManySignups'])->assertHeader('Retry-After');
});

test('login takes the username in any case, and doesn\'t say which half was wrong', function (): void {
    User::factory()->create(['username' => 'Reader', 'password' => 'long-enough']);
    $login = static fn(array $body) => fromGallery()->postJson('/api/auth/login', $body);

    $login(['username' => 'reader', 'password' => 'long-enough'])->assertOk()->assertExactJson(['user' => [
        'id' => 1, 'username' => 'Reader', 'is_admin' => false, 'created_at' => User::query()->firstOrFail()->created_at?->toIso8601String(),
    ]]);
    $login(['username' => 'reader', 'password' => 'wrong-password'])->assertStatus(401)
        ->assertExactJson(['error' => 'InvalidCredentials', 'message' => 'The username or password is incorrect.']);
    $login(['username' => 'nobody', 'password' => 'long-enough'])->assertStatus(401)
        ->assertExactJson(['error' => 'InvalidCredentials', 'message' => 'The username or password is incorrect.']);
    $login(['password' => 'long-enough'])->assertStatus(400)->assertJson(['error' => 'MissingCredentials']);
});

test('ten logins are allowed in five minutes; the eleventh is refused even with the right password', function (): void {
    User::factory()->create(['username' => 'reader', 'password' => 'long-enough']);
    foreach (range(1, 10) as $ignored) {
        fromGallery()->postJson('/api/auth/login', ['username' => 'reader', 'password' => 'wrong-password'])->assertStatus(401);
    }

    fromGallery()->postJson('/api/auth/login', ['username' => 'reader', 'password' => 'long-enough'])
        ->assertStatus(429)->assertJson(['error' => 'TooManyAttempts'])->assertHeader('Retry-After');
});

test('a browser stays signed in until it closes, or for 30 days with remember', function (): void {
    User::factory()->create(['username' => 'reader', 'password' => 'long-enough']);
    $once = signedInBrowser('reader');
    $kept = signedInBrowser('reader', remember: true);

    $once->restart();
    $kept->restart();
    $once->send('GET', '/api/auth/session')->assertExactJson(['user' => null]);
    $kept->send('GET', '/api/auth/session')->assertJsonPath('user.username', 'reader');

    travel(31)->days();
    $kept->restart();
    $kept->send('GET', '/api/auth/session')->assertExactJson(['user' => null]);
});

test('a session left a day without a request is signed out', function (): void {
    User::factory()->create(['username' => 'reader', 'password' => 'long-enough']);
    $browser = signedInBrowser('reader');

    travel(20)->hours();
    $browser->send('GET', '/api/auth/session')->assertJsonPath('user.username', 'reader');
    travel(20)->hours();
    $browser->send('GET', '/api/auth/session')->assertJsonPath('user.username', 'reader');
    travel(25)->hours();
    $browser->send('GET', '/api/auth/session')->assertExactJson(['user' => null]);
});

test('signing in starts a new session, so one planted beforehand signs no one in', function (): void {
    User::factory()->create(['username' => 'reader', 'password' => 'long-enough']);
    $browser = new TestBrowser();
    $browser->send('GET', '/api/auth/session')->assertExactJson(['user' => null]);
    $planted = new TestBrowser();
    $planted->send('GET', '/api/auth/session');

    $browser->send('POST', '/api/auth/login', ['username' => 'reader', 'password' => 'long-enough'])->assertOk();
    $planted->send('GET', '/api/auth/session')->assertExactJson(['user' => null]);
});

test('the admin\'s routes refuse an ordinary account, before saying whether a record exists', function (): void {
    $reader = User::factory()->create();

    asAccount($reader)->postJson('/api/tags', ['tag_name' => 'zebra'])->assertStatus(403)
        ->assertExactJson(['error' => 'Forbidden', 'message' => 'This needs an admin account.']);
    asAccount($reader)->deleteJson('/api/tags/999')->assertStatus(403);
    asAccount($reader)->getJson('/api/users')->assertStatus(403);
    fromGallery()->postJson('/api/tags', ['tag_name' => 'zebra'])->assertStatus(401);
    // A new tag goes in the General category.
    TagCategory::factory()->create(['category_id' => 1]);
    asAdmin()->postJson('/api/tags', ['tag_name' => 'zebra'])->assertCreated();
});

test('changing a password needs the current one, keeps this browser signed in, and signs out every other', function (): void {
    User::factory()->create(['username' => 'reader', 'password' => 'long-enough']);
    $here = signedInBrowser('reader', remember: true);
    $there = signedInBrowser('reader');
    $kept = signedInBrowser('reader', remember: true);

    $here->send('PATCH', '/api/account/password', ['current_password' => 'not-it', 'password' => 'brand-new-one'])
        ->assertStatus(403)->assertJson(['error' => 'IncorrectPassword']);
    $here->send('PATCH', '/api/account/password', ['current_password' => 'long-enough', 'password' => 'short'])
        ->assertStatus(400)->assertJson(['error' => 'WeakPassword']);
    $here->send('PATCH', '/api/account/password', ['current_password' => 'long-enough', 'password' => 'brand-new-one'])
        ->assertNoContent();

    $here->send('GET', '/api/lists')->assertOk();
    $here->restart();
    $here->send('GET', '/api/auth/session')->assertJsonPath('user.username', 'reader');

    // The other session is refused once, then reads as signed out.
    $there->send('GET', '/api/lists')->assertUnauthorized();
    $there->send('GET', '/api/auth/session')->assertExactJson(['user' => null]);
    $kept->restart();
    $kept->send('GET', '/api/auth/session')->assertExactJson(['user' => null]);
    fromGallery()->postJson('/api/auth/login', ['username' => 'reader', 'password' => 'brand-new-one'])->assertOk();
});

test('an account can delete itself, with what it kept; the last admin can\'t', function (): void {
    $user = User::factory()->create(['username' => 'reader', 'password' => 'long-enough']);
    $user->blockedTags()->attach(Tag::factory()->create()->tag_id);
    $browser = signedInBrowser('reader', remember: true);

    $browser->send('DELETE', '/api/account', ['password' => 'not-it'])->assertStatus(403)->assertJson(['error' => 'IncorrectPassword']);
    $browser->send('DELETE', '/api/account', ['password' => 'long-enough'])->assertNoContent();
    expect(User::query()->find($user->id))->toBeNull()
        ->and(FavoriteList::query()->where('user_id', $user->id)->exists())->toBeFalse()
        ->and($browser->hasCookie(TestBrowser::rememberCookie()))->toBeFalse();
    $browser->send('GET', '/api/auth/session')->assertExactJson(['user' => null]);

    $admin = adminUser();
    $admin->update(['password' => 'long-enough']);
    asAccount($admin)->deleteJson('/api/account', ['password' => 'long-enough'])
        ->assertStatus(409)->assertJson(['error' => 'LastAdmin']);
});

test('an admin lists accounts, makes admins, sets passwords and deletes accounts', function (): void {
    $reader = User::factory()->create(['username' => 'reader', 'password' => 'long-enough']);
    $readers = signedInBrowser('reader', remember: true);

    asAdmin()->getJson('/api/users')->assertOk()->assertJsonCount(2)->assertJsonPath('0.username', 'admin');
    asAdmin()->patchJson("/api/users/{$reader->id}", ['is_admin' => true])->assertOk()->assertJsonPath('is_admin', true);
    asAdmin()->patchJson("/api/users/{$reader->id}", ['password' => 'reset-password'])->assertOk();
    $readers->send('GET', '/api/lists')->assertUnauthorized();
    $readers->restart();
    $readers->send('GET', '/api/auth/session')->assertExactJson(['user' => null]);

    asAdmin()->patchJson('/api/users/' . adminUser()->id, ['is_admin' => false])->assertOk();
    asAccount($reader->refresh())->patchJson("/api/users/{$reader->id}", ['is_admin' => false])
        ->assertStatus(409)->assertJson(['error' => 'LastAdmin']);
    asAccount($reader)->deleteJson('/api/users/' . adminUser()->id)->assertNoContent();
    asAccount($reader)->deleteJson('/api/users/999')->assertNotFound()->assertJson(['error' => 'UserNotFound']);
});

test('logging out signs out this browser, at once, and no other', function (): void {
    User::factory()->create(['username' => 'reader', 'password' => 'long-enough']);
    $laptop = signedInBrowser('reader', remember: true);
    $phone = signedInBrowser('reader', remember: true);

    $laptop->send('DELETE', '/api/auth/session')->assertNoContent();
    $laptop->send('GET', '/api/lists')->assertUnauthorized();
    $laptop->restart();
    $laptop->send('GET', '/api/auth/session')->assertExactJson(['user' => null]);

    $phone->restart();
    $phone->send('GET', '/api/auth/session')->assertJsonPath('user.username', 'reader');
});

test('an account\'s own routes need a sign-in', function (string $method, string $path): void {
    fromGallery()->json($method, $path)->assertUnauthorized();
})->with([
    ['DELETE', '/api/auth/session'],
    ['PATCH', '/api/account/password'],
    ['DELETE', '/api/account'],
    ['GET', '/api/account/blocked-tags'],
    ['GET', '/api/lists'],
    ['GET', '/api/saved-searches'],
]);

test('anyone may still read, signed in or not, and a session that names no one reads as signed out', function (): void {
    signedOut()->getJson('/api/media/count')->assertOk();

    $stranger = new TestBrowser();
    $stranger->setCookie(config()->string('session.cookie'), str_repeat('x', 40));
    $stranger->send('GET', '/api/media/count')->assertOk();
    $stranger->send('GET', '/api/auth/session')->assertExactJson(['user' => null]);
});
