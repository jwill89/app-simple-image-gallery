<?php

declare(strict_types=1);

use Illuminate\Hashing\Argon2IdHasher;

// What config/session.php and config/hashing.php make of the environment: the
// session's driver, cookie name and flags, and the password hash's settings.

/** Sets an environment variable where Laravel's env() reads it, or removes it (null). */
function putEnvironment(string $name, ?string $value): void
{
    if ($value === null) {
        unset($_SERVER[$name], $_ENV[$name]);
        putenv($name);

        return;
    }

    $_SERVER[$name] = $_ENV[$name] = $value;
    putenv("{$name}={$value}");
}

/**
 * Evaluates one of the config files as if $vars were in the environment (a null
 * value is not set at all), then puts the environment back.
 *
 * @param array<string, string|null> $vars
 * @return array<string, mixed>
 */
function configFile(string $file, array $vars): array
{
    $saved = [];
    foreach ($vars as $name => $value) {
        $before = getenv($name);
        $saved[$name] = $before === false ? null : $before;
        putEnvironment($name, $value);
    }

    try {
        $config = require config_path($file);
    } finally {
        foreach ($saved as $name => $before) {
            putEnvironment($name, $before);
        }
    }

    if (!is_array($config)) {
        throw new UnexpectedValueException("config/{$file} did not return an array.");
    }

    /** @var array<string, mixed> $config */
    return $config;
}

/**
 * config/session.php as it comes out on a server at $url, with whatever else is in $more.
 *
 * @param array<string, string|null> $more
 * @return array<string, mixed>
 */
function sessionConfig(?string $url, array $more = []): array
{
    return configFile('session.php', [
        'APP_NAME' => 'Gallery',
        'APP_URL' => $url,
        'SESSION_DRIVER' => null,
        'SESSION_COOKIE' => null,
        'SESSION_PATH' => null,
        'SESSION_DOMAIN' => null,
        'SESSION_SECURE_COOKIE' => null,
        ...$more,
    ]);
}

it('keeps sessions in the database, unless told otherwise', function (): void {
    expect(sessionConfig('https://gallery.test')['driver'])->toBe('database')
        ->and(sessionConfig('https://gallery.test', ['SESSION_DRIVER' => 'array'])['driver'])->toBe('array');
});

it('names the session cookie __Host- on https, where the browser then holds it to this host alone', function (): void {
    $config = sessionConfig('https://gallery.test');

    expect($config['cookie'])->toBe('__Host-gallery-session')
        ->and($config['secure'])->toBeTrue()
        ->and($config['path'])->toBe('/')
        ->and($config['domain'])->toBeNull()
        ->and($config['http_only'])->toBeTrue()
        ->and($config['same_site'])->toBe('lax');
});

it('leaves the prefix off over plain http, where the browser would drop a cookie that has it', function (): void {
    $config = sessionConfig('http://localhost');

    expect($config['cookie'])->toBe('gallery-session')
        ->and($config['secure'])->toBeFalse()
        ->and(sessionConfig(null)['cookie'])->toBe('gallery-session');
});

it('leaves the prefix off wherever the browser would refuse it', function (array $more): void {
    expect(sessionConfig('https://gallery.test', $more)['cookie'])->toBe('gallery-session');
})->with([
    'a Domain attribute' => [['SESSION_DOMAIN' => 'gallery.test']],
    'a path below the root' => [['SESSION_PATH' => '/app']],
    'a cookie that is not Secure' => [['SESSION_SECURE_COOKIE' => 'false']],
]);

it('takes the cookie name from SESSION_COOKIE when it is set', function (): void {
    expect(sessionConfig('https://gallery.test', ['SESSION_COOKIE' => 'mine'])['cookie'])->toBe('mine');
});

it('hashes passwords with argon2id at 64 MiB, one pass and four lanes unless the tests lower it', function (): void {
    $config = configFile('hashing.php', ['ARGON_MEMORY' => null, 'ARGON_TIME' => null, 'ARGON_THREADS' => null]);

    expect($config['driver'])->toBe('argon2id')
        ->and($config['argon'])->toBe(['memory' => 65536, 'time' => 1, 'threads' => 4, 'verify' => false]);

    $hash = new Argon2IdHasher($config['argon'] ?? [])->make('long-enough');
    $options = password_get_info($hash)['options'];

    // Four lanes, or one where PHP's argon2 comes from libsodium, which has no more.
    expect($hash)->toStartWith('$argon2id$v=19$')
        ->and($options['memory_cost'])->toBe(65536)
        ->and($options['time_cost'])->toBe(1)
        ->and($options['threads'])->toBeIn([1, 4]);
});
