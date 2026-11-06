<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Testing\TestResponse;
use Laravel\Passkeys\Passkey;
use ParagonIE\ConstantTime\Base64UrlSafe;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\FakeAuthenticator;
use Tests\Support\TestBrowser;

use function Pest\Laravel\travel;

// Passkeys: an account adds them, its password first, and signs in with one
// instead of its password. Each challenge is answered once, within five
// minutes, by a passkey made for this site.

beforeEach(function (): void {
    config(['passkeys.relying_party_id' => 'gallery.test', 'passkeys.allowed_origins' => [GALLERY_ORIGIN]]);
});

/** A browser signed in as a new account, "reader" (password "long-enough"). */
function readerBrowser(): TestBrowser
{
    User::factory()->create(['username' => 'reader', 'password' => 'long-enough']);
    $browser = new TestBrowser();
    $browser->send('POST', '/api/auth/login', ['username' => 'reader', 'password' => 'long-enough'])->assertOk();

    return $browser;
}

/**
 * Add the passkey to the browser's account, as the Account page does.
 *
 * @return array<string, mixed> The passkey, as the server lists it.
 */
function addPasskey(TestBrowser $browser, FakeAuthenticator $passkey, ?string $name = null): array
{
    /** @var array<string, mixed> $options */
    $options = $browser->send('POST', '/api/account/passkeys/options', ['password' => 'long-enough'])->assertOk()->json();
    /** @var array<string, mixed> $added */
    $added = $browser->send('POST', '/api/account/passkeys', ['credential' => $passkey->create($options), ...($name === null ? [] : ['name' => $name])])
        ->assertCreated()
        ->json();

    return $added;
}

/**
 * Sign the browser in with the passkey, as the login page does.
 *
 * @return TestResponse<Response>
 */
function signInWithPasskey(TestBrowser $browser, FakeAuthenticator $passkey, bool $remember = false): TestResponse
{
    /** @var array<string, mixed> $options */
    $options = $browser->send('POST', '/api/auth/passkey/options')->assertOk()->json();

    return $browser->send('POST', '/api/auth/passkey', ['credential' => $passkey->get($options), 'remember' => $remember]);
}

test('an account adds a passkey with its password, then signs in with it alone', function (): void {
    $browser = readerBrowser();
    $phone = new FakeAuthenticator();

    expect(addPasskey($browser, $phone, 'My phone'))->toMatchArray(['name' => 'My phone', 'authenticator' => null, 'last_used_at' => null]);
    $browser->send('GET', '/api/account/passkeys')->assertOk()->assertJsonCount(1)->assertJsonPath('0.name', 'My phone');

    $elsewhere = new TestBrowser();
    signInWithPasskey($elsewhere, $phone)->assertOk()->assertJsonPath('user.username', 'reader')->assertJsonMissingPath('token');
    $elsewhere->send('GET', '/api/auth/session')->assertJsonPath('user.username', 'reader');
    expect(Passkey::query()->firstOrFail()->last_used_at)->not->toBeNull();
});

test('an unnamed passkey is named after where it is kept, or just "Passkey"', function (): void {
    $browser = readerBrowser();

    expect(addPasskey($browser, new FakeAuthenticator(aaguid: '08987058-cadc-4b81-b6e1-30de50dcbe96')))
        ->toMatchArray(['name' => 'Windows Hello', 'authenticator' => 'Windows Hello'])
        ->and(addPasskey($browser, new FakeAuthenticator()))->toMatchArray(['name' => 'Passkey', 'authenticator' => null]);
});

test('the sign-in challenge is for this site, needs the person, and names no account', function (): void {
    $options = new TestBrowser()->send('POST', '/api/auth/passkey/options')->assertOk()->json();

    expect($options)->toHaveKey('challenge')
        ->and($options['rpId'] ?? null)->toBe('gallery.test')
        ->and($options['userVerification'] ?? null)->toBe('required')
        ->and($options['allowCredentials'] ?? [])->toBe([]);
});

test('adding a passkey needs the password, and a challenge this browser asked for', function (): void {
    $browser = readerBrowser();

    $browser->send('POST', '/api/account/passkeys/options')->assertStatus(400)->assertJson(['error' => 'MissingCredentials']);
    $browser->send('POST', '/api/account/passkeys/options', ['password' => 'not-it'])->assertStatus(403)->assertJson(['error' => 'IncorrectPassword']);
    $browser->send('POST', '/api/account/passkeys', ['credential' => new FakeAuthenticator()->create(['challenge' => 'never-issued'])])
        ->assertStatus(400)->assertJson(['error' => 'PasskeyExpired']);
    $browser->send('POST', '/api/account/passkeys', ['credential' => ['id' => 'x']])->assertStatus(400)->assertJson(['error' => 'InvalidPasskey']);
    fromGallery()->postJson('/api/account/passkeys/options', ['password' => 'long-enough'])->assertUnauthorized();

    expect(Passkey::query()->count())->toBe(0);
});

test('a passkey answering for another site, or a challenge already answered, isn\'t added', function (): void {
    $browser = readerBrowser();
    /** @var array<string, mixed> $options */
    $options = $browser->send('POST', '/api/account/passkeys/options', ['password' => 'long-enough'])->json();

    $browser->send('POST', '/api/account/passkeys', ['credential' => new FakeAuthenticator()->create($options, origin: 'https://evil.example')])
        ->assertStatus(400)->assertJson(['error' => 'PasskeyRejected']);
    // That answer used the challenge up.
    $browser->send('POST', '/api/account/passkeys', ['credential' => new FakeAuthenticator()->create($options)])
        ->assertStatus(400)->assertJson(['error' => 'PasskeyExpired']);

    /** @var array<string, mixed> $options */
    $options = $browser->send('POST', '/api/account/passkeys/options', ['password' => 'long-enough'])->json();
    $browser->send('POST', '/api/account/passkeys', ['credential' => new FakeAuthenticator(rpId: 'evil.example')->create($options)])
        ->assertStatus(400)->assertJson(['error' => 'PasskeyRejected']);

    expect(Passkey::query()->count())->toBe(0);
});

test('the same passkey isn\'t added twice', function (): void {
    $browser = readerBrowser();
    $phone = new FakeAuthenticator();
    addPasskey($browser, $phone);

    /** @var array<string, mixed> $options */
    $options = $browser->send('POST', '/api/account/passkeys/options', ['password' => 'long-enough'])->json();
    expect($options['excludeCredentials'][0]['id'] ?? null)->toBe(Base64UrlSafe::encodeUnpadded($phone->credentialId));
    $browser->send('POST', '/api/account/passkeys', ['credential' => $phone->create($options)])
        ->assertStatus(400)->assertJson(['error' => 'PasskeyRejected']);
    expect(Passkey::query()->count())->toBe(1);
});

test('a sign-in challenge waits five minutes, and is answered once', function (): void {
    $phone = new FakeAuthenticator();
    addPasskey(readerBrowser(), $phone);
    $browser = new TestBrowser();

    /** @var array<string, mixed> $options */
    $options = $browser->send('POST', '/api/auth/passkey/options')->json();
    travel(6)->minutes();
    $browser->send('POST', '/api/auth/passkey', ['credential' => $phone->get($options)])->assertStatus(400)->assertJson(['error' => 'PasskeyExpired']);

    /** @var array<string, mixed> $options */
    $options = $browser->send('POST', '/api/auth/passkey/options')->json();
    $answer = $phone->get($options);
    $browser->send('POST', '/api/auth/passkey', ['credential' => $answer])->assertOk();

    // The same answer, sent again to a new challenge.
    $replay = new TestBrowser();
    $replay->send('POST', '/api/auth/passkey/options')->assertOk();
    $replay->send('POST', '/api/auth/passkey', ['credential' => $answer])->assertStatus(401)->assertJson(['error' => 'PasskeyNotAccepted']);
    $replay->send('GET', '/api/auth/session')->assertExactJson(['user' => null]);
});

test('a removed passkey, one for another site, or one never added signs no one in', function (): void {
    $browser = readerBrowser();
    $phone = new FakeAuthenticator();
    $added = addPasskey($browser, $phone);

    // Its own challenge, answered from a page on another site.
    $stranger = new TestBrowser();
    /** @var array<string, mixed> $options */
    $options = $stranger->send('POST', '/api/auth/passkey/options')->json();
    $stranger->send('POST', '/api/auth/passkey', ['credential' => $phone->get($options, origin: 'https://evil.example')])
        ->assertStatus(401)->assertJson(['error' => 'PasskeyNotAccepted']);
    signInWithPasskey(new TestBrowser(), new FakeAuthenticator())->assertStatus(401)->assertJson(['error' => 'PasskeyNotAccepted']);
    signInWithPasskey($stranger, $phone)->assertOk();

    $browser->send('DELETE', "/api/account/passkeys/{$added['id']}")->assertNoContent();
    signInWithPasskey(new TestBrowser(), $phone)->assertStatus(401)->assertJson(['error' => 'PasskeyNotAccepted']);
});

test('a passkey sign-in can keep the browser signed in, and counts against the login limit', function (): void {
    $phone = new FakeAuthenticator();
    addPasskey(readerBrowser(), $phone);

    $kept = new TestBrowser();
    signInWithPasskey($kept, $phone, remember: true)->assertOk();
    $kept->restart();
    $kept->send('GET', '/api/auth/session')->assertJsonPath('user.username', 'reader');

    // Two logins so far (the password one above, and that); eight more fill the ten.
    foreach (range(1, 8) as $ignored) {
        fromGallery()->postJson('/api/auth/login', ['username' => 'reader', 'password' => 'wrong-password'])->assertStatus(401);
    }
    signInWithPasskey(new TestBrowser(), $phone)->assertStatus(429)->assertJson(['error' => 'TooManyAttempts']);
});

test('an account renames and removes its own passkeys, and no one else\'s', function (): void {
    $browser = readerBrowser();
    $added = addPasskey($browser, new FakeAuthenticator());
    $path = "/api/account/passkeys/{$added['id']}";

    $browser->send('PATCH', $path, ['name' => '  Laptop  '])->assertOk()->assertJsonPath('name', 'Laptop');
    $browser->send('PATCH', $path, ['name' => '   '])->assertStatus(400)->assertJson(['error' => 'InvalidPasskeyName']);
    $browser->send('PATCH', $path, ['name' => str_repeat('x', 65)])->assertStatus(400)->assertJson(['error' => 'InvalidPasskeyName']);

    $other = User::factory()->create();
    asAccount($other)->patchJson($path, ['name' => 'Mine'])->assertNotFound()->assertJson(['error' => 'PasskeyNotFound']);
    asAccount($other)->deleteJson($path)->assertNotFound();
    asAccount($other)->getJson('/api/account/passkeys')->assertExactJson([]);

    $browser->send('DELETE', $path)->assertNoContent();
    $browser->send('DELETE', '/api/account/passkeys/999')->assertNotFound()->assertJson(['error' => 'PasskeyNotFound']);
    $browser->send('GET', '/api/account/passkeys')->assertExactJson([]);
});

test('an account\'s passkeys go with it', function (): void {
    $browser = readerBrowser();
    addPasskey($browser, new FakeAuthenticator());

    $browser->send('DELETE', '/api/account', ['password' => 'long-enough'])->assertNoContent();
    expect(Passkey::query()->count())->toBe(0);
});
