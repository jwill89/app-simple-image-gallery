<?php

declare(strict_types=1);

namespace Tests\Support\Contract;

use App\Models\User;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use LogicException;
use Symfony\Component\HttpFoundation\Response;
use Tests\Support\ContractFixture;
use Tests\Support\ContractFixtureSeeder;

use function Pest\Laravel\call;
use function Pest\Laravel\seed;

/**
 * Replays the Slim app's recorded contract (tests/Fixtures/contract/, format
 * in its README) against this app, in the setup it was recorded in.
 *
 * A case runs step by step while its routes exist here. A step whose path
 * belongs to an inventory route that isn't ported yet stops the case, and
 * run() reports what it waits on. A step's expected response is the recorded
 * one unless the contract was changed on purpose (tests/Feature/Contract/
 * divergences.php).
 */
final class Replay
{
    /** The tokens the recorder put in the database for each case. */
    public const string ADMIN_TOKEN = 'c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00c0ffee00';

    public const string EXPIRED_TOKEN = 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef';

    /** The .env the recordings were made with, and the config key each variable is. */
    private const array ENV = [
        'GALLERY_ADMIN_PASSWORD' => ['gallery.admin_password', 'contract-password'],
        'GALLERY_URL' => ['gallery.url', 'https://gallery.test'],
        'GALLERY_ALLOWED_ORIGINS' => ['gallery.allowed_origins', 'https://gallery.test,http://localhost:5173'],
        'DANBOORU_LOGIN' => ['gallery.danbooru.login', 'contract'],
        'DANBOORU_API_KEY' => ['gallery.danbooru.api_key', 'contract-key'],
        'FONTAWESOME_KIT_ID' => ['gallery.fontawesome_kit_id', ''],
        'DUPLICATE_MAX_DISTANCE' => ['gallery.duplicates.max_distance', ''],
        'DUPLICATE_MAX_REGION_DIFF' => ['gallery.duplicates.max_region_diff', ''],
    ];

    /** The recording server's upload_max_filesize (1M), so a larger generated file is refused as PHP refuses it. */
    private const int UPLOAD_MAX_FILESIZE = 1024 * 1024;

    /** The fixture report's age, as the recorder set it: older than any report a case writes. */
    private const int FIXTURE_REPORT_MTIME = 1761782400;

    /** Access-Control headers a browser reads only on a preflight; Laravel sends them only there. */
    private const array PREFLIGHT_ONLY_HEADERS = ['access-control-allow-headers', 'access-control-allow-methods', 'access-control-max-age'];

    /**
     * SecurityHeaders' two headers, which the Slim app set on its API's
     * responses only. Here every response has them, the SPA shell's included.
     */
    private const array SECURITY_HEADERS = ['x-content-type-options' => 'nosniff', 'x-frame-options' => 'SAMEORIGIN'];

    /** @var array<string, string> Values captured from earlier steps' responses. */
    private array $captured = [];

    private DanbooruStub $danbooru;

    /** The case's copy of the fixture media/ and dupes/ folders. */
    private string $root;

    /**
     * @param array<string, mixed> $recording
     * @param array<int, array<string, mixed>> $divergences Expected responses that replace recorded ones, by step.
     */
    public function __construct(private readonly array $recording, private readonly array $divergences = []) {}

    /**
     * Every recording, by case id.
     *
     * @return array<string, array<string, mixed>>
     */
    public static function recordings(): array
    {
        // Plain PHP, not the File facade: Pest builds datasets before the app boots.
        $directory = ContractFixture::path('contract');
        $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS));
        $recordings = [];
        foreach ($files as $file) {
            if (!$file instanceof \SplFileInfo || $file->getExtension() !== 'json') {
                continue;
            }
            $id = str_replace('\\', '/', substr($file->getPathname(), strlen($directory) + 1, -5));
            if ($id !== 'index') {
                $recordings[$id] = ContractFixture::recording($id);
            }
        }
        ksort($recordings);

        return $recordings;
    }

    /**
     * Run the case's steps until one needs a route not ported yet.
     *
     * @return array{ran: int, of: int, waits_on: list<string>, problems: list<string>}
     */
    public function run(): array
    {
        $this->setUp();

        try {
            return $this->runSteps();
        } finally {
            File::deleteDirectory($this->root);
        }
    }

    /** @return array{ran: int, of: int, waits_on: list<string>, problems: list<string>} */
    private function runSteps(): array
    {
        /** @var list<array<string, mixed>> $steps */
        $steps = $this->recording['steps'];
        $problems = [];
        foreach ($steps as $index => $step) {
            /** @var array<string, mixed> $request */
            $request = $step['request'];
            $waitsOn = self::unportedRoutesFor(self::string($request, 'method'), self::string($request, 'path'));
            if ($waitsOn !== []) {
                return ['ran' => $index, 'of' => count($steps), 'waits_on' => $waitsOn, 'problems' => $problems];
            }

            /** @var array<string, mixed> $expected */
            $expected = $this->divergences[$index] ?? $step['response'];
            if (!str_starts_with(self::string($request, 'path'), '/api')) {
                /** @var array<string, string> $headers */
                $headers = $expected['headers'] ?? [];
                $expected['headers'] = $headers + self::SECURITY_HEADERS;
            }
            foreach ($this->runStep($step, $expected) as $problem) {
                $problems[] = "step {$index} (" . $request['method'] . ' ' . $request['path'] . "): {$problem}";
            }
        }

        return ['ran' => count($steps), 'of' => count($steps), 'waits_on' => [], 'problems' => $problems];
    }

    /** Fixture rows and files, the recorded .env, the recorder's tokens, Danbooru, then the case's own setup. */
    private function setUp(): void
    {
        /** @var array{env?: array<string, string>, sql?: list<string>, files?: array<string, string|null>, danbooru?: string} $setup */
        $setup = $this->recording['setup'] ?? [];
        $this->danbooru = DanbooruStub::install(up: ($setup['danbooru'] ?? 'up') !== 'down');
        // The recording host had no ffmpeg or ffprobe: no thumbnails, and video
        // metadata from getimagesize() alone.
        Process::fake(['*' => Process::result(errorOutput: 'command not found', exitCode: 127)]);

        foreach (self::ENV as $name => [$key, $value]) {
            $value = $setup['env'][$name] ?? $value;
            config([$key => $key === 'gallery.allowed_origins' ? array_map(trim(...), explode(',', $value)) : $value]);
        }
        config(['cors.allowed_origins' => config()->array('gallery.allowed_origins')]);

        seed(ContractFixtureSeeder::class);
        foreach ($setup['sql'] ?? [] as $statement) {
            DB::connection()->getPdo()->exec($statement);
        }

        $admin = User::admin();
        $admin->tokens()->create(['name' => 'admin', 'token' => hash('sha256', self::ADMIN_TOKEN), 'abilities' => ['*']]);
        $expired = $admin->tokens()->create(['name' => 'admin', 'token' => hash('sha256', self::EXPIRED_TOKEN), 'abilities' => ['*']]);
        $expired->forceFill(['created_at' => Carbon::createFromTimestamp(1700000000)])->save();

        $this->root = $root = sys_get_temp_dir() . '/gallery-replay-' . bin2hex(random_bytes(4));
        config([
            'gallery.media_path' => "{$root}/media",
            'gallery.dupes_path' => "{$root}/dupes",
            // The built index.html the SPA shell was recorded with.
            'gallery.spa_index' => ContractFixture::path('spa/index.html'),
        ]);
        File::copyDirectory(ContractFixture::path('media'), "{$root}/media");
        File::ensureDirectoryExists("{$root}/media/full");
        File::copyDirectory(ContractFixture::path('dupes'), "{$root}/dupes");
        foreach (File::files("{$root}/dupes") as $report) {
            touch($report->getPathname(), self::FIXTURE_REPORT_MTIME);
        }

        foreach ($setup['files'] ?? [] as $path => $content) {
            $this->applyFile($path, $content, $root);
        }
    }

    /** A recorded file change, at the path it has here: the spec, the SPA's index.html, or under media/ or dupes/. */
    private function applyFile(string $path, ?string $content, string $root): void
    {
        if ($path === 'dist/index.html' && $content === null) {
            config(['gallery.spa_index' => "{$root}/no-spa/index.html"]);

            return;
        }
        if ($path === 'openapi.json' && $content === null) {
            // The committed spec isn't deleted for this: File reports it missing.
            $spec = base_path('openapi.json');
            File::partialMock()->shouldReceive('isFile')->andReturnUsing(static fn(string $file): bool => $file !== $spec && is_file($file));

            return;
        }
        if (!str_starts_with($path, 'media') && !str_starts_with($path, 'dupes')) {
            throw new LogicException("The replay has no place for the recorded file {$path}.");
        }

        $target = "{$root}/{$path}";
        if ($content === null) {
            File::isDirectory($target) ? File::deleteDirectory($target) : File::delete($target);
        } else {
            File::ensureDirectoryExists(dirname($target));
            File::put($target, $content);
        }
    }

    /**
     * @param array<string, mixed> $step
     * @param array<string, mixed> $expected
     * @return list<string>
     */
    private function runStep(array $step, array $expected): array
    {
        /** @var array<string, mixed> $request */
        $request = $step['request'];
        $repeat = is_int($step['repeat'] ?? null) ? $step['repeat'] : 1;

        $statuses = [];
        // The base response: a preflight's is a plain Symfony response, with no status().
        for ($i = 1; $i < $repeat; $i++) {
            $statuses[] = $this->send($request)->baseResponse->getStatusCode();
        }
        $response = $this->send($request);
        $statuses[] = $response->baseResponse->getStatusCode();

        $problems = [];
        $counts = array_count_values($statuses);
        $recordedCounts = is_array($step['statuses'] ?? null) ? $step['statuses'] : [];
        ksort($counts);
        ksort($recordedCounts);
        if ($repeat > 1 && $counts !== $recordedCounts) {
            $problems[] = 'the repeated sends answered ' . json_encode($counts) . ', recorded ' . json_encode($recordedCounts);
        }

        // A later request's <captured:NAME> is the NAME field of the latest
        // response that had one (the recordings use it for login's token).
        $body = json_decode((string) $response->baseResponse->getContent(), true);
        foreach (is_array($body) && !array_is_list($body) ? $body : [] as $name => $value) {
            if (is_string($value)) {
                $this->captured[$name] = $value;
            }
        }

        // The Danbooru calls it made, credentials left out, as the stub logged them.
        $calls = $this->danbooru->takeCalls();
        if ($calls !== ($step['danbooru_calls'] ?? [])) {
            $problems[] = 'Danbooru calls ' . json_encode($calls, JSON_UNESCAPED_SLASHES) . ', recorded ' . json_encode($step['danbooru_calls'] ?? [], JSON_UNESCAPED_SLASHES);
        }

        return [...$problems, ...self::compare($expected, $response->baseResponse, self::string($request, 'method') === 'OPTIONS')];
    }

    /**
     * @param array<string, mixed> $request
     * @return TestResponse<Response>
     */
    private function send(array $request): TestResponse
    {
        /** @var array<string, string> $headers */
        $headers = $request['headers'] ?? [];
        $server = [];
        foreach ($headers as $name => $value) {
            if ($name === 'Authorization') {
                $value = str_replace('<admin-token>', self::ADMIN_TOKEN, $value);
                $value = (string) preg_replace_callback('/<captured:(\w+)>/', fn(array $m): string => $this->captured[$m[1]] ?? '', $value);
            }
            $server[$name === 'Content-Type' ? 'CONTENT_TYPE' : 'HTTP_' . strtoupper(str_replace('-', '_', $name))] = $value;
        }

        $parameters = [];
        $files = [];
        $content = null;
        if (array_key_exists('json', $request)) {
            $content = json_encode($request['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        } elseif (isset($request['form'])) {
            /** @var array<string, string> $parameters */
            $parameters = $request['form'];
        } elseif (isset($request['body'])) {
            $content = self::string($request, 'body');
        } elseif (isset($request['multipart'])) {
            /** @var list<array<string, int|string>> $parts */
            $parts = $request['multipart'];
            [$parameters, $files] = $this->multipart($parts);
        }

        // A request to a path takes the app URL's host; one with a Host header
        // (the SPA shell's, whose tags name the host) goes to that host.
        $path = self::string($request, 'path');
        $url = isset($headers['Host']) ? "http://{$headers['Host']}{$path}" : $path;

        return call(self::string($request, 'method'), $url, $parameters, [], $files, $server, $content);
    }

    /**
     * A recorded multipart body as form fields and uploaded files. A file part
     * names a fixture under uploads/ or a size to generate; each is sent from
     * a copy, which the upload moves away. A generated file over the recording
     * server's upload_max_filesize arrives as PHP hands such a file over: an
     * UPLOAD_ERR_INI_SIZE error, with no content.
     *
     * @param list<array<string, int|string>> $parts
     * @return array{0: array<string, string>, 1: array<string, list<UploadedFile>>}
     */
    private function multipart(array $parts): array
    {
        $fields = [];
        $files = [];
        foreach ($parts as $part) {
            $name = (string) $part['name'];
            if (!isset($part['filename'])) {
                $fields[$name] = (string) $part['value'];

                continue;
            }

            $copy = $this->root . '/uploads/' . bin2hex(random_bytes(4));
            File::ensureDirectoryExists(dirname($copy));
            $size = (int) ($part['generated_bytes'] ?? 0);
            $error = $size > self::UPLOAD_MAX_FILESIZE ? UPLOAD_ERR_INI_SIZE : null;
            if (isset($part['file'])) {
                File::copy(ContractFixture::path((string) $part['file']), $copy);
            } elseif ($error === null) {
                File::put($copy, str_repeat("\0", $size));
            }

            $files[rtrim($name, '[]')][] = new UploadedFile($copy, (string) $part['filename'], (string) $part['type'], $error, true);
        }

        return [$fields, $files];
    }

    /**
     * How a response differs from the expected one: status, the contract's
     * headers, and the body, with the README's placeholders.
     *
     * @param array<string, mixed> $expected
     * @return list<string>
     */
    public static function compare(array $expected, Response $actual, bool $isPreflight): array
    {
        $problems = [];
        if ($actual->getStatusCode() !== $expected['status']) {
            $problems[] = "status {$actual->getStatusCode()}, expected {$expected['status']}";
        }

        $body = (string) $actual->getContent();
        /** @var array<string, string> $expectedHeaders */
        $expectedHeaders = $expected['headers'] ?? [];
        foreach (Headers::CONTRACT as $name) {
            $want = $expectedHeaders[$name] ?? null;
            $got = $actual->headers->get($name);
            $skip = ($name === 'cache-control' && $want === null)
                || ($name === 'content-type' && ($expected['text'] ?? null) === '')
                || (!$isPreflight && in_array($name, self::PREFLIGHT_ONLY_HEADERS, true));
            $matches = $name === 'cache-control' ? Matcher::cacheControl($want, $got) : Matcher::header($want, $got);
            if (!$skip && !$matches) {
                $problems[] = "header {$name}: " . json_encode($got) . ', expected ' . json_encode($want);
            }
        }

        if (array_key_exists('json', $expected)) {
            $decoded = json_decode($body, true);
            $mismatch = Matcher::json($expected['json'], $decoded);
            if ($mismatch !== null) {
                $problems[] = "body {$mismatch}";
            }
        } elseif (array_key_exists('text', $expected)) {
            if ($body !== $expected['text']) {
                $problems[] = 'body ' . json_encode(mb_strimwidth($body, 0, 200, '…')) . ', expected ' . json_encode(mb_strimwidth((string) $expected['text'], 0, 200, '…'));
            }
        } elseif (isset($expected['same_as'])) {
            if ($body !== File::get(base_path((string) preg_replace('#^backend/#', '', (string) $expected['same_as'])))) {
                $problems[] = "body differs from {$expected['same_as']}";
            }
        }

        return $problems;
    }

    /**
     * The ported routes a request depends on that aren't ported yet: every
     * inventory route whose path pattern matches (any method, since a 405's
     * Allow lists them all). A path no inventory route matches depends on none,
     * and neither does a preflight, which CORS handling answers before routing.
     *
     * @return list<string>
     */
    private static function unportedRoutesFor(string $method, string $path): array
    {
        if ($method === 'OPTIONS') {
            return [];
        }

        $ported = [];
        foreach (Route::getRoutes()->getRoutes() as $route) {
            foreach (array_diff($route->methods(), ['HEAD']) as $routeMethod) {
                $ported[$routeMethod . ' /' . preg_replace('#^api/?#', '', $route->uri())] = true;
            }
        }

        $waitsOn = [];
        foreach (Inventory::routes() as $route) {
            if (preg_match(Inventory::pattern($route), $path) === 1 && !isset($ported[$route])) {
                $waitsOn[] = $route;
            }
        }

        return $waitsOn;
    }

    /** @param array<string, mixed> $array */
    private static function string(array $array, string $key): string
    {
        $value = $array[$key] ?? null;

        return is_string($value) ? $value : throw new LogicException("The recording has no {$key}.");
    }
}
