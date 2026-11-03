<?php

declare(strict_types=1);

use App\Models\FavoriteList;
use App\Models\Tag;
use App\Models\TagCategory;
use App\Models\User;

use function Pest\Laravel\travel;

// API 6's accounts: sign-up and login by username and password (the auth/
// recordings divergences.php supersedes point here), the admin role, an
// account's own password and deletion, and the admin's Users page.

const ORIGIN = ['Origin' => 'https://gallery.test'];

beforeEach(function (): void {
    config(['gallery.allowed_origins' => ['https://gallery.test'], 'cors.allowed_origins' => ['https://gallery.test'], 'gallery.allow_signup' => true]);
});

test('signing up makes an ordinary account with a Favorites list, and signs it in', function (): void {
    $response = asAccount(ORIGIN)->postJson('/api/auth/register', ['username' => 'Reader', 'password' => 'long-enough', 'is_admin' => true])
        ->assertCreated()
        ->assertJsonPath('user.username', 'Reader')
        ->assertJsonPath('user.is_admin', false);
    $token = $response->json('token');

    expect($token)->toBeString()->toMatch('/^[0-9a-f]{64}$/');
    asAccount(['Authorization' => "Bearer {$token}"])->getJson('/api/auth/me')->assertOk()->assertJsonPath('username', 'Reader');
    expect(FavoriteList::query()->where('is_default', true)->value('name'))->toBe('Favorites');
});

test('a username is checked, and taken whatever its letter case', function (): void {
    User::factory()->create(['username' => 'reader']);
    $register = static fn(array $body) => asAccount(ORIGIN)->postJson('/api/auth/register', $body);

    $register(['username' => 'READER', 'password' => 'long-enough'])->assertStatus(409)->assertJson(['error' => 'UsernameTaken']);
    $register(['username' => 'no spaces', 'password' => 'long-enough'])->assertStatus(400)->assertJson(['error' => 'InvalidUsername']);
    $register(['username' => 'ab', 'password' => 'long-enough'])->assertStatus(400)->assertJson(['error' => 'InvalidUsername']);
    $register(['username' => 'fine', 'password' => 'short'])->assertStatus(400)
        ->assertExactJson(['error' => 'WeakPassword', 'message' => 'A password is at least 8 characters.', 'errors' => ['password' => ['A password is at least 8 characters.']]]);
});

test('sign-up can be closed, and is limited to five accounts an hour per address', function (): void {
    config(['gallery.allow_signup' => false]);
    asAccount(ORIGIN)->postJson('/api/auth/register', ['username' => 'closed', 'password' => 'long-enough'])
        ->assertStatus(403)->assertJson(['error' => 'SignupClosed']);

    config(['gallery.allow_signup' => true]);
    foreach (range(1, 5) as $n) {
        asAccount(ORIGIN)->postJson('/api/auth/register', ['username' => "reader{$n}", 'password' => 'long-enough'])->assertCreated();
    }
    asAccount(ORIGIN)->postJson('/api/auth/register', ['username' => 'reader6', 'password' => 'long-enough'])
        ->assertStatus(429)->assertJson(['error' => 'TooManySignups'])->assertHeader('Retry-After');
});

test('login takes the username in any case, and doesn\'t say which half was wrong', function (): void {
    User::factory()->create(['username' => 'Reader', 'password' => 'long-enough']);
    $login = static fn(array $body) => asAccount(ORIGIN)->postJson('/api/auth/login', $body);

    $login(['username' => 'reader', 'password' => 'long-enough'])->assertOk()->assertJsonPath('user.username', 'Reader');
    $login(['username' => 'reader', 'password' => 'wrong-password'])->assertStatus(401)
        ->assertExactJson(['error' => 'InvalidCredentials', 'message' => 'The username or password is incorrect.']);
    $login(['username' => 'nobody', 'password' => 'long-enough'])->assertStatus(401)
        ->assertExactJson(['error' => 'InvalidCredentials', 'message' => 'The username or password is incorrect.']);
    $login(['password' => 'long-enough'])->assertStatus(400)->assertJson(['error' => 'MissingCredentials']);
});

test('ten logins are allowed in five minutes; the eleventh is refused even with the right password', function (): void {
    User::factory()->create(['username' => 'reader', 'password' => 'long-enough']);
    foreach (range(1, 10) as $ignored) {
        asAccount(ORIGIN)->postJson('/api/auth/login', ['username' => 'reader', 'password' => 'wrong-password'])->assertStatus(401);
    }

    asAccount(ORIGIN)->postJson('/api/auth/login', ['username' => 'reader', 'password' => 'long-enough'])
        ->assertStatus(429)->assertJson(['error' => 'TooManyAttempts'])->assertHeader('Retry-After');
});

test('a login lasts a day, or 30 with remember', function (): void {
    User::factory()->create(['username' => 'reader', 'password' => 'long-enough']);
    $session = asAccount(ORIGIN)->postJson('/api/auth/login', ['username' => 'reader', 'password' => 'long-enough'])->json('token');
    $remembered = asAccount(ORIGIN)->postJson('/api/auth/login', ['username' => 'reader', 'password' => 'long-enough', 'remember' => true])->json('token');

    travel(25)->hours();
    asAccount(['Authorization' => "Bearer {$session}"])->getJson('/api/auth/me')->assertUnauthorized();
    asAccount(['Authorization' => "Bearer {$remembered}"])->getJson('/api/auth/me')->assertOk();

    travel(30)->days();
    asAccount(['Authorization' => "Bearer {$remembered}"])->getJson('/api/auth/me')->assertUnauthorized();
});

test('the admin\'s routes refuse an ordinary account, before saying whether a record exists', function (): void {
    $reader = accountHeaders();

    asAccount($reader)->postJson('/api/tags', ['tag_name' => 'zebra'])->assertStatus(403)
        ->assertExactJson(['error' => 'Forbidden', 'message' => 'This needs an admin account.']);
    asAccount($reader)->deleteJson('/api/tags/999')->assertStatus(403);
    asAccount($reader)->getJson('/api/users')->assertStatus(403);
    asAccount(ORIGIN)->postJson('/api/tags', ['tag_name' => 'zebra'])->assertStatus(401);
    // A new tag goes in the General category.
    TagCategory::factory()->create(['category_id' => 1]);
    asAccount(adminHeaders())->postJson('/api/tags', ['tag_name' => 'zebra'])->assertCreated();
});

test('changing a password needs the current one, and signs out every other login', function (): void {
    $user = User::factory()->create(['username' => 'reader', 'password' => 'long-enough']);
    $other = $user->issueToken();
    $headers = accountHeaders($user);

    asAccount($headers)->patchJson('/api/account/password', ['current_password' => 'not-it', 'password' => 'brand-new-one'])
        ->assertStatus(403)->assertJson(['error' => 'IncorrectPassword']);
    asAccount($headers)->patchJson('/api/account/password', ['current_password' => 'long-enough', 'password' => 'short'])
        ->assertStatus(400)->assertJson(['error' => 'WeakPassword']);
    asAccount($headers)->patchJson('/api/account/password', ['current_password' => 'long-enough', 'password' => 'brand-new-one'])
        ->assertNoContent();

    asAccount($headers)->getJson('/api/auth/me')->assertOk();
    asAccount(['Authorization' => "Bearer {$other}"])->getJson('/api/auth/me')->assertUnauthorized();
    asAccount(ORIGIN)->postJson('/api/auth/login', ['username' => 'reader', 'password' => 'brand-new-one'])->assertOk();
});

test('an account can delete itself, with what it kept; the last admin can\'t', function (): void {
    $user = User::factory()->create(['password' => 'long-enough']);
    $user->blockedTags()->attach(Tag::factory()->create()->tag_id);
    $headers = accountHeaders($user);

    asAccount($headers)->deleteJson('/api/account', ['password' => 'not-it'])->assertStatus(403)->assertJson(['error' => 'IncorrectPassword']);
    asAccount($headers)->deleteJson('/api/account', ['password' => 'long-enough'])->assertNoContent();
    expect(User::query()->find($user->id))->toBeNull()
        ->and(FavoriteList::query()->where('user_id', $user->id)->exists())->toBeFalse();

    $admin = adminUser();
    $admin->update(['password' => 'long-enough']);
    asAccount(accountHeaders($admin))->deleteJson('/api/account', ['password' => 'long-enough'])
        ->assertStatus(409)->assertJson(['error' => 'LastAdmin']);
});

test('an admin lists accounts, makes admins, sets passwords and deletes accounts', function (): void {
    $admin = adminHeaders();
    $reader = User::factory()->create(['username' => 'reader']);
    $token = $reader->issueToken();

    asAccount($admin)->getJson('/api/users')->assertOk()->assertJsonCount(2)->assertJsonPath('0.username', 'admin');
    asAccount($admin)->patchJson("/api/users/{$reader->id}", ['is_admin' => true])->assertOk()->assertJsonPath('is_admin', true);
    asAccount($admin)->patchJson("/api/users/{$reader->id}", ['password' => 'reset-password'])->assertOk();
    asAccount(['Authorization' => "Bearer {$token}"])->getJson('/api/auth/me')->assertUnauthorized();

    asAccount($admin)->patchJson('/api/users/' . adminUser()->id, ['is_admin' => false])->assertOk();
    asAccount(accountHeaders($reader->refresh()))->patchJson("/api/users/{$reader->id}", ['is_admin' => false])
        ->assertStatus(409)->assertJson(['error' => 'LastAdmin']);
    asAccount(accountHeaders($reader))->deleteJson('/api/users/' . adminUser()->id)->assertNoContent();
    asAccount(accountHeaders($reader))->deleteJson('/api/users/999')->assertNotFound()->assertJson(['error' => 'UserNotFound']);
});

test('logging out revokes the token at once', function (): void {
    $headers = accountHeaders();

    asAccount($headers)->deleteJson('/api/auth/token')->assertNoContent();
    asAccount($headers)->getJson('/api/auth/me')->assertUnauthorized();
});

test('an account\'s own routes need a sign-in', function (string $method, string $path): void {
    asAccount(ORIGIN)->json($method, $path)->assertUnauthorized();
})->with([
    ['GET', '/api/auth/me'],
    ['PATCH', '/api/account/password'],
    ['DELETE', '/api/account'],
    ['GET', '/api/account/blocked-tags'],
    ['GET', '/api/lists'],
    ['GET', '/api/saved-searches'],
]);

test('anyone may still read, signed in or not, and a bad token reads as no token', function (): void {
    signedOut()->getJson('/api/media/count')->assertOk();
    asAccount(['Authorization' => 'Bearer not-a-real-token'])->getJson('/api/media/count')->assertOk();
});
