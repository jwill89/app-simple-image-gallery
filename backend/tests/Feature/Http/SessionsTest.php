<?php

declare(strict_types=1);

use App\Models\User;
use App\Support\HashedSessionHandler;
use Illuminate\Support\Facades\DB;
use Tests\Support\TestBrowser;

// Where sessions live and what the browser holds: rows in the gallery's SQLite
// database found by the hash of the session id, and a cookie named for the
// browser's __Host- rule. (The other tests run on array sessions.)

beforeEach(function (): void {
    config(['session.driver' => 'database']);
});

/** A browser signed in as a new account called reader. */
function sessionBrowser(): TestBrowser
{
    User::factory()->create(['username' => 'reader', 'password' => 'long-enough']);
    $browser = new TestBrowser();
    $browser->send('POST', '/api/auth/login', ['username' => 'reader', 'password' => 'long-enough'])->assertOk();

    return $browser;
}

test('a session is a row of the sessions table, found by the SHA-256 of its id, not by the id', function (): void {
    $browser = sessionBrowser();
    $id = $browser->cookie(config()->string('session.cookie')) ?? '';
    $row = DB::table('sessions')->sole();

    expect($id)->not->toBe('')
        ->and($row->id)->toBe(hash('sha256', $id))
        ->and($row->id)->toBe(HashedSessionHandler::key($id))
        ->and($row->user_id)->toBe(User::query()->where('username', 'reader')->value('id'))
        ->and(DB::table('sessions')->where('id', $id)->exists())->toBeFalse();

    // The browser stays signed in on the id it holds.
    $browser->send('GET', '/api/auth/session')->assertOk()->assertJsonPath('user.username', 'reader');
    expect(DB::table('sessions')->where('id', $row->id)->exists())->toBeTrue();
});

test('logging out removes the session\'s row, and signing in moves it to a new id', function (): void {
    $browser = new TestBrowser();
    $browser->send('GET', '/api/auth/session')->assertOk();
    $before = $browser->cookie(config()->string('session.cookie')) ?? '';

    User::factory()->create(['username' => 'reader', 'password' => 'long-enough']);
    $browser->send('POST', '/api/auth/login', ['username' => 'reader', 'password' => 'long-enough'])->assertOk();
    $signedIn = $browser->cookie(config()->string('session.cookie')) ?? '';
    expect($signedIn)->not->toBe($before)
        ->and(DB::table('sessions')->where('id', hash('sha256', $before))->exists())->toBeFalse()
        ->and(DB::table('sessions')->where('id', hash('sha256', $signedIn))->exists())->toBeTrue();

    $browser->send('DELETE', '/api/auth/session')->assertNoContent();
    expect(DB::table('sessions')->where('id', hash('sha256', $signedIn))->exists())->toBeFalse();
});

test('a cookie naming a session that is not in the table is a stranger', function (): void {
    $browser = sessionBrowser();
    DB::table('sessions')->delete();

    $browser->send('GET', '/api/auth/session')->assertExactJson(['user' => null]);
});

test('a session id stored as it is, the way Laravel keeps it, signs no one in', function (): void {
    $browser = sessionBrowser();
    $id = $browser->cookie(config()->string('session.cookie')) ?? '';
    DB::table('sessions')->update(['id' => $id]);

    $browser->send('GET', '/api/auth/session')->assertExactJson(['user' => null]);
});

test('the encrypted session store hashes the id too', function (): void {
    config(['session.encrypt' => true]);
    $browser = sessionBrowser();
    $id = $browser->cookie(config()->string('session.cookie')) ?? '';

    expect(DB::table('sessions')->where('id', hash('sha256', $id))->exists())->toBeTrue();
    $browser->send('GET', '/api/auth/session')->assertJsonPath('user.username', 'reader');
});

test('the sweep removes sessions idle past the lifetime, and only those', function (): void {
    $handler = app('session')->driver()->getHandler();
    DB::table('sessions')->insert([
        ['id' => 'old', 'payload' => '', 'last_activity' => time() - 100_000],
        ['id' => 'recent', 'payload' => '', 'last_activity' => time()],
    ]);

    expect($handler)->toBeInstanceOf(HashedSessionHandler::class)
        ->and($handler->open('', ''))->toBeTrue()
        ->and($handler->gc(86_400))->toBe(1)
        ->and($handler->close())->toBeTrue()
        ->and(DB::table('sessions')->pluck('id')->all())->toBe(['recent']);
});

test('the cookie a __Host- name needs: Secure, HttpOnly, SameSite=Lax, for the whole host and no other', function (): void {
    config(['session.cookie' => '__Host-gallery-session', 'session.secure' => true]);
    User::factory()->create(['username' => 'reader', 'password' => 'long-enough']);

    $cookie = new TestBrowser()->send('POST', '/api/auth/login', ['username' => 'reader', 'password' => 'long-enough'])
        ->assertOk()
        ->getCookie('__Host-gallery-session', decrypt: false);

    expect($cookie)->not->toBeNull()
        ->and($cookie?->isSecure())->toBeTrue()
        ->and($cookie?->isHttpOnly())->toBeTrue()
        ->and($cookie?->getSameSite())->toBe('lax')
        ->and($cookie?->getPath())->toBe('/')
        ->and($cookie?->getDomain())->toBeNull();
});
