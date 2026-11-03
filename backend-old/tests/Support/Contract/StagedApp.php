<?php

declare(strict_types=1);

namespace Gallery\Tests\Support\Contract;

use PDO;
use RuntimeException;

/**
 * A throwaway copy of the Slim app, served with `php -S`, that the contract
 * recorder can reset to the fixture between cases.
 *
 * The app resolves its database, response cache and logs from its own code's
 * location (__DIR__), and its media and dupes folders from the directory above
 * api/. So the code is copied into a temporary root rather than run in place,
 * and nothing a recorded write does can reach backend-old/db/gallery.db,
 * backend-old/media or backend-old/cache. The copy is the code as it is now; only
 * DanbooruTagger's API base URL is rewritten, to point at the stub server.
 */
final class StagedApp
{
    /** Bearer token inserted into every fresh copy of the database. */
    public const string ADMIN_TOKEN = 'c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00';

    /** A token inserted alongside it, issued long enough ago to have expired. */
    public const string EXPIRED_TOKEN = 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef';

    public const string ADMIN_PASSWORD = 'contract-password';

    public const string ALLOWED_ORIGIN = 'https://gallery.test';

    public const string DEV_ORIGIN = 'http://localhost:5173';

    /** The .env the staged app reads; a case can override any of it. */
    private const array BASE_ENV = [
        'GALLERY_ADMIN_PASSWORD' => self::ADMIN_PASSWORD,
        'GALLERY_URL' => self::ALLOWED_ORIGIN,
        'GALLERY_ALLOWED_ORIGINS' => self::ALLOWED_ORIGIN . ',' . self::DEV_ORIGIN,
        'DANBOORU_LOGIN' => 'contract',
        'DANBOORU_API_KEY' => 'contract-key',
        'FONTAWESOME_KIT_ID' => '',
        'DUPLICATE_MAX_DISTANCE' => '',
        'DUPLICATE_MAX_REGION_DIFF' => '',
    ];

    /** Environment variables the app reads, kept out of the servers' inherited environment. */
    private const string APP_ENV_PATTERN = '/^(GALLERY_|DANBOORU_|DUPLICATE_|FONTAWESOME_)/';

    /** The fixture report's age: older than any report a recorded scan writes. */
    private const int FIXTURE_REPORT_MTIME = 1761782400;

    private readonly string $root;

    private readonly string $stubRoot;

    private readonly string $template;

    private int $appPort = 0;

    private int $stubPort = 0;

    /** @var resource|null */
    private $appServer;

    /** @var resource|null */
    private $stubServer;

    /** @var array<string, string>|null The .env the running app server was started with. */
    private ?array $appEnv = null;

    public function __construct(private readonly string $backendDir)
    {
        $base = sys_get_temp_dir() . '/gallery-contract-' . bin2hex(random_bytes(4));
        $this->root = $base . '/app';
        $this->stubRoot = $base . '/danbooru';
        $this->template = $base . '/fixture.db';
    }

    public function fixturesDir(): string
    {
        return $this->backendDir . '/tests/Fixtures';
    }

    public function baseUrl(): string
    {
        return 'http://127.0.0.1:' . $this->appPort;
    }

    /**
     * Copy the app, build the fixture database and start the Danbooru stub.
     */
    public function stage(): void
    {
        self::copyTree($this->backendDir . '/api', $this->root . '/api');
        self::copyTree($this->backendDir . '/includes', $this->root . '/includes');
        self::copyFile($this->backendDir . '/openapi.json', $this->root . '/openapi.json');
        self::copyFile($this->backendDir . '/index.php', $this->root . '/index.php');
        self::makeDir($this->stubRoot);

        $this->stubPort = self::freePort();
        $this->pointTaggerAtStub();
        $this->writeAutoloadShim();
        $this->writeRouter();
        $this->buildTemplate();
        $this->startStub();
    }

    /**
     * Put the database, media, dupes and cache back to the fixture, then apply
     * a case's setup: SQL run on the fresh database, and files written (string)
     * or removed (null), relative to the app root.
     *
     * @param list<string> $sql
     * @param array<string, string|null> $files
     */
    public function reset(array $sql = [], array $files = []): void
    {
        foreach (['db', 'media', 'dupes', 'cache', 'logs', 'dist'] as $dir) {
            self::removeTree($this->root . '/' . $dir);
        }

        self::copyFile($this->template, $this->root . '/db/gallery.db');
        self::copyFile($this->fixturesDir() . '/spa/index.html', $this->root . '/dist/index.html');
        self::copyTree($this->fixturesDir() . '/media', $this->root . '/media');
        self::makeDir($this->root . '/media/full');
        self::copyTree($this->fixturesDir() . '/dupes', $this->root . '/dupes');
        foreach (self::entries($this->root . '/dupes') as $report) {
            touch($this->root . '/dupes/' . $report, self::FIXTURE_REPORT_MTIME);
        }

        $db = new PDO('sqlite:' . $this->root . '/db/gallery.db');
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec('PRAGMA foreign_keys = ON');
        $insertToken = $db->prepare('INSERT INTO auth_tokens (token, created_at) VALUES (?, ?)');
        $insertToken->execute([self::ADMIN_TOKEN, time()]);
        $insertToken->execute([self::EXPIRED_TOKEN, 1700000000]);
        foreach ($sql as $statement) {
            $db->exec($statement);
        }
        unset($db);

        foreach ($files as $path => $content) {
            $target = $this->root . '/' . $path;
            if ($content === null) {
                is_dir($target) ? self::removeTree($target) : @unlink($target);
            } else {
                self::makeDir(dirname($target));
                file_put_contents($target, $content);
            }
        }

        @unlink($this->stubRoot . '/calls.log');
    }

    /**
     * Run the app server with the base .env plus $overrides, restarting it if
     * it was started with anything else. Configuration putenv()s .env values
     * into the server process on its first request, so a changed .env only
     * takes effect in a new process.
     *
     * @param array<string, string> $overrides
     */
    public function useEnv(array $overrides): void
    {
        $env = $overrides + self::BASE_ENV;
        if ($this->appServer !== null && $env === $this->appEnv) {
            return;
        }

        self::stopServer($this->appServer);
        $lines = array_map(static fn(string $key, string $value): string => "{$key}={$value}", array_keys($env), $env);
        file_put_contents($this->root . '/.env', implode("\n", $lines) . "\n");

        $this->appPort = self::freePort();
        // Production's .htaccess allows 100M uploads; a 1M limit lets a small
        // generated file stand in for one that is too large.
        $this->appServer = $this->startServer($this->appPort, $this->root, $this->root . '/router.php', [
            'upload_max_filesize=1M',
            'post_max_size=8M',
        ]);
        $this->appEnv = $env;
    }

    /** Start or stop the Danbooru stub (stopped, the app sees a network failure). */
    public function stubUp(bool $up): void
    {
        if ($up && $this->stubServer === null) {
            $this->startStub();
        } elseif (!$up) {
            self::stopServer($this->stubServer);
        }
    }

    /**
     * The calls the Danbooru stub received since the last reset, then clears them.
     *
     * @return list<string>
     */
    public function takeDanbooruCalls(): array
    {
        $log = $this->stubRoot . '/calls.log';
        $calls = is_file($log) ? file($log, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) : false;
        @unlink($log);

        return $calls === false ? [] : $calls;
    }

    public function stop(): void
    {
        self::stopServer($this->appServer);
        self::stopServer($this->stubServer);
    }

    public function remove(): void
    {
        $this->stop();
        self::removeTree(dirname($this->root));
    }

    public function path(string $relative): string
    {
        return $this->root . '/' . $relative;
    }

    private function startStub(): void
    {
        $this->stubServer = $this->startServer($this->stubPort, $this->stubRoot, $this->fixturesDir() . '/danbooru-stub.php');
    }

    private function pointTaggerAtStub(): void
    {
        $file = $this->root . '/includes/Gallery/Core/DanbooruTagger.php';
        $code = (string) file_get_contents($file);
        $patched = str_replace("'https://danbooru.donmai.us'", "'http://127.0.0.1:{$this->stubPort}'", $code, $count);
        if ($count !== 1) {
            throw new RuntimeException('DanbooruTagger::API_BASE was not found exactly once; update StagedApp::pointTaggerAtStub().');
        }
        file_put_contents($file, $patched);
    }

    /**
     * vendor/autoload.php for the copy: Composer's real autoloader for the
     * dependencies, with the copied Gallery\ and Routes\ classes loaded ahead of
     * the classmap entries that point back into backend-old/.
     */
    private function writeAutoloadShim(): void
    {
        $vendor = var_export(str_replace('\\', '/', $this->backendDir) . '/vendor/autoload.php', true);
        self::makeDir($this->root . '/vendor');
        file_put_contents($this->root . '/vendor/autoload.php', <<<PHP
            <?php

            \$loader = require {$vendor};

            spl_autoload_register(static function (string \$class): void {
                foreach (['Gallery\\\\' => __DIR__ . '/../includes/Gallery/', 'Routes\\\\' => __DIR__ . '/../api/Routes/'] as \$prefix => \$dir) {
                    if (str_starts_with(\$class, \$prefix)) {
                        \$file = \$dir . str_replace('\\\\', '/', substr(\$class, strlen(\$prefix))) . '.php';
                        if (is_file(\$file)) {
                            require \$file;
                        }
                        return;
                    }
                }
            }, true, true);

            return \$loader;

            PHP);
    }

    /**
     * php -S router: /api/* goes to the Slim app, and every other path to the
     * SPA front controller, index.php, as the .htaccess files send them in
     * production. (Production serves real files first; no case asks for one.)
     */
    private function writeRouter(): void
    {
        file_put_contents($this->root . '/router.php', <<<'ROUTER'
            <?php

            $path = (string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
            if ($path === '/api' || str_starts_with($path, '/api/')) {
                require __DIR__ . '/api/index.php';
                return true;
            }

            require __DIR__ . '/index.php';
            return true;

            ROUTER);
    }

    private function buildTemplate(): void
    {
        $db = new PDO('sqlite:' . $this->template);
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec((string) file_get_contents($this->backendDir . '/tests/Support/schema.sql'));
        $db->exec((string) file_get_contents($this->fixturesDir() . '/contract.sql'));
        unset($db);
    }

    /**
     * @param list<string> $ini Extra -d settings.
     * @return resource
     */
    private function startServer(int $port, string $docRoot, string $router, array $ini = [])
    {
        $command = [PHP_BINARY, '-d', 'xdebug.mode=off', '-d', 'display_errors=0', '-d', 'log_errors=1'];
        foreach ($ini as $setting) {
            array_push($command, '-d', $setting);
        }
        array_push($command, '-S', "127.0.0.1:{$port}", '-t', $docRoot, $router);

        $env = array_filter(getenv(), static fn(string $key): bool => preg_match(self::APP_ENV_PATTERN, $key) !== 1, ARRAY_FILTER_USE_KEY);
        $log = dirname($this->root) . '/servers.log';
        $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['file', $log, 'a'], 2 => ['file', $log, 'a']], $pipes, $docRoot, $env);
        if ($process === false) {
            throw new RuntimeException("Could not start php -S on port {$port}.");
        }

        $deadline = microtime(true) + 10;
        while (microtime(true) < $deadline) {
            $socket = @fsockopen('127.0.0.1', $port, $errno, $error, 0.2);
            if ($socket !== false) {
                fclose($socket);

                return $process;
            }
            usleep(50_000);
        }

        throw new RuntimeException("php -S did not start listening on port {$port}; see {$log}.");
    }

    /**
     * @param resource|null $process
     * @param-out null $process
     */
    private static function stopServer(&$process): void
    {
        if ($process !== null) {
            proc_terminate($process);
            proc_close($process);
            $process = null;
        }
    }

    private static function freePort(): int
    {
        $server = stream_socket_server('tcp://127.0.0.1:0');
        if ($server === false) {
            throw new RuntimeException('Could not find a free port.');
        }
        $name = (string) stream_socket_get_name($server, false);
        fclose($server);

        return (int) substr($name, (int) strrpos($name, ':') + 1);
    }

    private static function makeDir(string $dir): void
    {
        if (!is_dir($dir) && !mkdir($dir, 0o777, true) && !is_dir($dir)) {
            throw new RuntimeException("Could not create {$dir}.");
        }
    }

    private static function copyFile(string $from, string $to): void
    {
        self::makeDir(dirname($to));
        if (!copy($from, $to)) {
            throw new RuntimeException("Could not copy {$from}.");
        }
    }

    private static function copyTree(string $from, string $to): void
    {
        self::makeDir($to);
        foreach (self::entries($from) as $entry) {
            is_dir("{$from}/{$entry}") ? self::copyTree("{$from}/{$entry}", "{$to}/{$entry}") : self::copyFile("{$from}/{$entry}", "{$to}/{$entry}");
        }
    }

    private static function removeTree(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            if (!unlink($path)) {
                throw new RuntimeException("Could not remove {$path}.");
            }

            return;
        }
        if (!is_dir($path)) {
            return;
        }
        foreach (self::entries($path) as $entry) {
            self::removeTree("{$path}/{$entry}");
        }
        rmdir($path);
    }

    /**
     * A directory's entries, without . and ..
     *
     * @return list<string>
     */
    private static function entries(string $dir): array
    {
        $entries = is_dir($dir) ? scandir($dir) : false;

        return $entries === false ? [] : array_values(array_diff($entries, ['.', '..']));
    }
}
