<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Tests\Support\TestBrowser;

// Passwords are hashed with argon2id, and a hash made another way (bcrypt, from
// before 7.2.0, or argon2id with weaker settings) is replaced at the sign-in
// that proves the password. (config/hashing.php's own settings are in
// SignInConfigTest; these tests run with the cheaper ones phpunit.xml gives.)

beforeEach(function (): void {
    config(['gallery.allow_signup' => true]);
});

/**
 * The settings a stored hash was made with.
 *
 * @return array{algo: string|null, algoName: string, options: array<string, mixed>}
 */
function hashInfo(string $hash): array
{
    return password_get_info($hash);
}

/** Replaces an account's stored hash behind the model's back, as an older release left it. */
function storeHash(User $user, string $hash): void
{
    DB::table('users')->where('id', $user->id)->update(['password' => $hash]);
}

test('new passwords are argon2id: signing up, an account\'s own change, an admin\'s reset', function (): void {
    fromGallery()->postJson('/api/auth/register', ['username' => 'reader', 'password' => 'long-enough'])->assertCreated();
    $reader = User::query()->where('username', 'reader')->firstOrFail();
    expect(hashInfo($reader->password)['algoName'])->toBe('argon2id')
        ->and($reader->password)->toStartWith('$argon2id$v=19$m=1024,t=1,p=1$');

    asAccount($reader)->patchJson('/api/account/password', ['current_password' => 'long-enough', 'password' => 'another-one'])->assertNoContent();
    expect(hashInfo($reader->refresh()->password)['algoName'])->toBe('argon2id');

    asAdmin()->patchJson("/api/users/{$reader->id}", ['password' => 'reset-by-admin'])->assertOk();
    expect(hashInfo($reader->refresh()->password)['algoName'])->toBe('argon2id')
        ->and(Hash::check('reset-by-admin', $reader->password))->toBeTrue();
});

test('signing in with a bcrypt hash works, and leaves an argon2id one behind', function (): void {
    $reader = User::factory()->create(['username' => 'reader', 'remember_token' => 'kept-token']);
    storeHash($reader, password_hash('long-enough', PASSWORD_BCRYPT, ['cost' => 4]));
    expect(hashInfo($reader->refresh()->password)['algoName'])->toBe('bcrypt');

    $browser = new TestBrowser();
    $browser->send('POST', '/api/auth/login', ['username' => 'reader', 'password' => 'long-enough'])->assertOk();

    $reader->refresh();
    expect(hashInfo($reader->password)['algoName'])->toBe('argon2id')
        ->and(Hash::check('long-enough', $reader->password))->toBeTrue()
        ->and($reader->getRememberToken())->toBe('kept-token');

    // The browser that rehashed it is the one that stays signed in.
    $browser->send('GET', '/api/auth/session')->assertJsonPath('user.username', 'reader');
});

test('a hash made with weaker argon2id settings is replaced when they rise', function (): void {
    $reader = User::factory()->create(['username' => 'reader']);
    storeHash($reader, password_hash('long-enough', PASSWORD_ARGON2ID, ['memory_cost' => 64, 'time_cost' => 1, 'threads' => 1]));
    expect(hashInfo($reader->refresh()->password)['options'])->toMatchArray(['memory_cost' => 64]);

    fromGallery()->postJson('/api/auth/login', ['username' => 'reader', 'password' => 'long-enough'])->assertOk();

    expect(hashInfo($reader->refresh()->password)['options'])->toMatchArray(['memory_cost' => 1024, 'time_cost' => 1, 'threads' => 1])
        ->and(Hash::check('long-enough', $reader->password))->toBeTrue();
});

test('a hash already made with today\'s settings is left as it is', function (): void {
    $reader = User::factory()->create(['username' => 'reader', 'password' => 'long-enough']);
    $stored = $reader->password;

    fromGallery()->postJson('/api/auth/login', ['username' => 'reader', 'password' => 'long-enough'])->assertOk();

    expect($reader->refresh()->password)->toBe($stored);
});

test('a wrong password leaves an old hash alone, since nothing proved the password', function (): void {
    $reader = User::factory()->create(['username' => 'reader']);
    $bcrypt = password_hash('long-enough', PASSWORD_BCRYPT, ['cost' => 4]);
    storeHash($reader, $bcrypt);

    fromGallery()->postJson('/api/auth/login', ['username' => 'reader', 'password' => 'not-it'])->assertStatus(401);

    expect($reader->refresh()->password)->toBe($bcrypt);
});

test('rehashing changes the stored hash and nothing else about the account, the remember token included', function (): void {
    $reader = User::factory()->create(['username' => 'reader', 'remember_token' => 'kept-token']);
    $reader->rehashPassword('long-enough');

    expect($reader->refresh()->getRememberToken())->toBe('kept-token')
        ->and(Hash::check('long-enough', $reader->password))->toBeTrue();
});

test('a name no account has costs one hash check, against a hash made once', function (): void {
    Hash::shouldReceive('make')->once()->andReturn('$argon2id$stand-in');
    Hash::shouldReceive('check')->twice()->andReturn(false);

    foreach (['nobody', 'no-one-either'] as $name) {
        fromGallery()->postJson('/api/auth/login', ['username' => $name, 'password' => 'long-enough'])
            ->assertStatus(401)->assertJson(['error' => 'InvalidCredentials']);
    }
});
