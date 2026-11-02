<?php

declare(strict_types=1);

namespace Gallery\Tests\Support\Contract;

use RuntimeException;
use stdClass;

/**
 * Runs the contract cases against a StagedApp and turns each one into a
 * recording: the requests sent and the responses that came back, with the
 * handful of values that differ from run to run replaced by placeholders.
 * The format and the placeholders are described in
 * tests/Fixtures/contract/README.md.
 */
final readonly class Recorder
{
    /** The endpoint inventory in docs/laravel-migration.md. Every one needs a case. */
    public const array ROUTES = [
        'GET /version',
        'GET /openapi.json',
        'GET /docs',
        'POST /auth/login',
        'GET /media/page/{page}/{per_page?}',
        'GET /media/untagged/{page}/{per_page?}',
        'GET /media/with-tags/{tag_list}/{page}/{per_page?}',
        'GET /media/random',
        'GET /media/count',
        'POST /media/by-ids',
        'POST /media/bulk-delete',
        'POST /media',
        'GET /media/{media_id}',
        'DELETE /media/{media_id}',
        'GET /media/{media_id}/tags',
        'PATCH /media/{media_id}/tags',
        'DELETE /media/{media_id}/tags/{tag_id}',
        'POST /media/{media_id}/danbooru-tags',
        'GET /tags',
        'GET /tags/display',
        'GET /tags/{tag_id}',
        'POST /tags',
        'PUT /tags/{tag_id}',
        'DELETE /tags/{tag_id}',
        'DELETE /tags/{tag_id}/migrate-to/{target_tag_id}',
        'POST /tags/{tag_id}/migrate',
        'GET /tag-categories',
        'POST /tag-categories',
        'PUT /tag-categories/{category_id}',
        'DELETE /tag-categories/{category_id}',
        'GET /tag-implications',
        'POST /tag-implications',
        'DELETE /tag-implications/{tag_id}/{implied_tag_id}',
        'GET /danbooru/category-mappings',
        'POST /danbooru/category-mappings',
        'DELETE /danbooru/category-mappings/{danbooru_category_id}',
        'GET /danbooru/tag-mappings',
        'POST /danbooru/tag-mappings',
        'PUT /danbooru/tag-mappings/{id}',
        'DELETE /danbooru/tag-mappings/{id}',
        'GET /duplicates/report',
        'POST /duplicates/scan',
        'POST /duplicates/dismissals',
    ];

    /** Behavior that belongs to no single route: unmatched paths and CORS preflight. */
    public const array CROSS_CUTTING = ['ANY /{unmatched}', 'OPTIONS /{any}'];

    public const string FOREIGN_ORIGIN = 'https://evil.example';

    /** Response headers that are part of the contract; everything else is the server's. */
    private const array HEADERS = [
        'content-type',
        'x-cache',
        'x-ratelimit-remaining',
        'retry-after',
        'cache-control',
        'allow',
        'access-control-allow-origin',
        'access-control-allow-credentials',
        'access-control-allow-headers',
        'access-control-allow-methods',
        'access-control-max-age',
        'x-frame-options',
        'x-content-type-options',
    ];

    /** Bodies larger than this are recorded by digest. */
    private const int MAX_INLINE_BODY = 65536;

    private const string BOUNDARY = 'contract-recording-boundary';

    /** Reads probed around a case's steps: one per cache group (media, tags). */
    private const array CACHE_PROBES = ['/api/media/count', '/api/tags'];

    public function __construct(private StagedApp $app)
    {
    }

    /**
     * Check that every inventory route has a case and every case names a known route.
     *
     * @param list<array<string, mixed>> $cases
     */
    public static function checkCoverage(array $cases): void
    {
        $known = [...self::ROUTES, ...self::CROSS_CUTTING];
        $covered = [];
        $ids = [];
        foreach ($cases as $case) {
            $route = self::string($case, 'route');
            $id = self::string($case, 'id');
            if (!in_array($route, $known, true)) {
                throw new RuntimeException("Case {$id} names an unknown route: {$route}");
            }
            if (isset($ids[$id])) {
                throw new RuntimeException("Duplicate case id: {$id}");
            }
            $ids[$id] = true;
            $covered[$route] = true;
        }

        $missing = array_diff(self::ROUTES, array_keys($covered));
        if ($missing !== []) {
            throw new RuntimeException('Routes with no recorded case: ' . implode(', ', $missing));
        }
    }

    /**
     * Record one case on a fresh copy of the fixture.
     *
     * @param array<string, mixed> $case
     * @return array<string, mixed>
     */
    public function record(array $case): array
    {
        /** @var array<string, string> $env */
        $env = $case['env'] ?? [];
        /** @var list<string> $sql */
        $sql = $case['sql'] ?? [];
        /** @var array<string, string|null> $files */
        $files = $case['files'] ?? [];
        $danbooruUp = ($case['danbooru'] ?? 'up') === 'up';

        $this->app->useEnv($env);
        $this->app->stubUp($danbooruUp);
        $this->app->reset($sql, $files);

        /** @var list<array<string, mixed>> $steps */
        $steps = $case['steps'];
        if (($case['probe'] ?? false) === true) {
            $probes = array_map(static fn(string $path): array => Request::get($path, ['probe' => true]), self::CACHE_PROBES);
            $steps = [...$probes, ...$steps, ...$probes];
        }

        $captured = [];
        $recorded = [];
        foreach ($steps as $step) {
            $recorded[] = $this->runStep($step, $captured);
        }

        $setup = array_filter([
            'env' => $env,
            'sql' => $sql,
            'files' => $files,
            'danbooru' => $danbooruUp ? null : 'down',
        ], static fn(mixed $value): bool => $value !== [] && $value !== null);

        return [
            'id' => $case['id'],
            'route' => $case['route'],
            'about' => $case['about'],
        ] + ($setup === [] ? [] : ['setup' => $setup]) + ['steps' => $recorded];
    }

    /**
     * @param array<string, mixed> $step
     * @param array<string, string> $captured
     * @return array<string, mixed>
     */
    private function runStep(array $step, array &$captured): array
    {
        $method = self::string($step, 'method');
        $path = self::string($step, 'path');
        if ($method === '') {
            throw new RuntimeException("A step for {$path} has no method.");
        }
        $isWrite = !in_array($method, ['GET', 'OPTIONS'], true);

        // What is sent, and what the recording shows in its place.
        $send = ['Accept' => '*/*'];
        $shown = ['Accept' => '*/*'];

        $origin = $step['origin'] ?? ($isWrite ? 'allowed' : 'none');
        $originValue = match ($origin) {
            'allowed' => StagedApp::ALLOWED_ORIGIN,
            'dev' => StagedApp::DEV_ORIGIN,
            'foreign' => self::FOREIGN_ORIGIN,
            'none' => null,
            default => throw new RuntimeException("Unknown origin: {$path}"),
        };
        if ($originValue !== null) {
            $send['Origin'] = $shown['Origin'] = $originValue;
        }
        if (isset($step['referer'])) {
            $send['Referer'] = $shown['Referer'] = self::string($step, 'referer');
        }

        $auth = self::string($step + ['auth' => $isWrite ? 'admin' : 'none'], 'auth');
        if ($auth === 'admin') {
            $send['Authorization'] = 'Bearer ' . StagedApp::ADMIN_TOKEN;
            $shown['Authorization'] = 'Bearer <admin-token>';
        } elseif ($auth === 'expired') {
            $send['Authorization'] = $shown['Authorization'] = 'Bearer deadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeefdeadbeef';
        } elseif (str_starts_with($auth, 'captured:')) {
            $name = substr($auth, 9);
            $send['Authorization'] = 'Bearer ' . ($captured[$name] ?? throw new RuntimeException("Nothing captured as {$name}"));
            $shown['Authorization'] = "Bearer <captured:{$name}>";
        } elseif (str_starts_with($auth, 'raw:')) {
            $send['Authorization'] = $shown['Authorization'] = substr($auth, 4);
        } elseif ($auth !== 'none') {
            throw new RuntimeException("Unknown auth: {$auth}");
        }

        /** @var array<string, string> $extraHeaders */
        $extraHeaders = $step['headers'] ?? [];
        foreach ($extraHeaders as $name => $value) {
            $send[$name] = $shown[$name] = $value;
        }

        [$body, $contentType, $shownBody] = $this->body($step);
        if ($contentType !== null) {
            $send['Content-Type'] = $contentType;
            $shown['Content-Type'] = str_starts_with($contentType, 'multipart/') ? 'multipart/form-data' : $contentType;
        }

        $request = ['method' => $method, 'path' => $path, 'headers' => $shown] + $shownBody;

        $repeat = is_int($step['repeat'] ?? null) ? $step['repeat'] : 1;
        $statuses = [];
        for ($i = 1; $i < $repeat; $i++) {
            $statuses[] = $this->send($method, $path, $send, $body)['status'];
            $this->app->takeDanbooruCalls();
        }
        $response = $this->send($method, $path, $send, $body);
        $statuses[] = $response['status'];

        /** @var array<string, string> $capture */
        $capture = $step['capture'] ?? [];
        foreach ($capture as $name => $key) {
            $decoded = json_decode($response['body'], true);
            $value = is_array($decoded) ? ($decoded[$key] ?? null) : null;
            $captured[$name] = is_string($value) ? $value : throw new RuntimeException("Could not capture {$key} from {$path}");
        }

        $result = ($step['probe'] ?? false) === true ? ['probe' => true] : [];
        if ($repeat > 1) {
            $result['repeat'] = $repeat;
            $result['statuses'] = array_count_values(array_map(strval(...), $statuses));
        }
        $result['request'] = $request;
        $result['response'] = $this->describeResponse($response);

        $calls = $this->app->takeDanbooruCalls();
        if ($calls !== []) {
            $result['danbooru_calls'] = $calls;
        }

        return $result;
    }

    /**
     * The body to send, its Content-Type, and how the recording shows it.
     *
     * @param array<string, mixed> $step
     * @return array{0: string|null, 1: string|null, 2: array<string, mixed>}
     */
    private function body(array $step): array
    {
        if (array_key_exists('json', $step)) {
            $json = json_encode($step['json'], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);

            return [$json, 'application/json', ['json' => $step['json']]];
        }
        if (isset($step['form'])) {
            /** @var array<string, string> $form */
            $form = $step['form'];

            return [http_build_query($form), 'application/x-www-form-urlencoded', ['form' => $form]];
        }
        if (isset($step['raw'])) {
            $raw = self::string($step, 'raw');
            $type = self::string($step + ['contentType' => 'application/json'], 'contentType');

            return [$raw, $type, ['body' => $raw]];
        }
        if (isset($step['multipart'])) {
            /** @var list<array<string, mixed>> $parts */
            $parts = $step['multipart'];

            return $this->multipart($parts);
        }

        return [null, null, []];
    }

    /**
     * @param list<array<string, mixed>> $parts
     * @return array{0: string, 1: string, 2: array<string, mixed>}
     */
    private function multipart(array $parts): array
    {
        $body = '';
        $shown = [];
        foreach ($parts as $part) {
            $name = self::string($part, 'name');
            $body .= '--' . self::BOUNDARY . "\r\n";

            if (array_key_exists('value', $part)) {
                $value = self::string($part, 'value');
                $body .= "Content-Disposition: form-data; name=\"{$name}\"\r\n\r\n{$value}\r\n";
                $shown[] = ['name' => $name, 'value' => $value];
                continue;
            }

            if (isset($part['bytes'])) {
                $size = is_int($part['bytes']) ? $part['bytes'] : 0;
                $content = str_repeat("\0", $size);
                $source = ['generated_bytes' => $size];
            } else {
                $fixture = self::string($part, 'file');
                $content = (string) file_get_contents($this->app->fixturesDir() . '/' . $fixture);
                $source = ['file' => $fixture];
            }
            $filename = self::string($part + ['filename' => 'upload.bin'], 'filename');
            $type = self::string($part + ['type' => 'application/octet-stream'], 'type');

            $body .= "Content-Disposition: form-data; name=\"{$name}\"; filename=\"{$filename}\"\r\n"
                . "Content-Type: {$type}\r\n\r\n{$content}\r\n";
            $shown[] = ['name' => $name, 'filename' => $filename, 'type' => $type] + $source;
        }

        return [$body . '--' . self::BOUNDARY . "--\r\n", 'multipart/form-data; boundary=' . self::BOUNDARY, ['multipart' => $shown]];
    }

    /**
     * @param non-empty-string $method
     * @param array<string, string> $headers
     * @return array{status: int, headers: array<string, string>, body: string}
     */
    private function send(string $method, string $path, array $headers, ?string $body): array
    {
        $received = [];
        $curl = curl_init($this->app->baseUrl() . $path);
        $lines = array_map(static fn(string $name, string $value): string => "{$name}: {$value}", array_keys($headers), $headers);
        curl_setopt_array($curl, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => [...$lines, 'Expect:'],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$received): int {
                $colon = strpos($line, ':');
                if ($colon !== false) {
                    $received[strtolower(trim(substr($line, 0, $colon)))] = trim(substr($line, $colon + 1));
                }

                return strlen($line);
            },
        ]);
        if ($body !== null) {
            curl_setopt($curl, CURLOPT_POSTFIELDS, $body);
        }

        $responseBody = curl_exec($curl);
        if (!is_string($responseBody)) {
            throw new RuntimeException("{$method} {$path} failed: " . curl_error($curl));
        }

        /** @var array<string, string> $received */
        return ['status' => curl_getinfo($curl, CURLINFO_RESPONSE_CODE), 'headers' => $received, 'body' => $responseBody];
    }

    /**
     * @param array{status: int, headers: array<string, string>, body: string} $response
     * @return array<string, mixed>
     */
    private function describeResponse(array $response): array
    {
        $body = $response['body'];
        $headers = [];
        foreach (self::HEADERS as $name) {
            if (isset($response['headers'][$name])) {
                $headers[$name] = $name === 'retry-after' && ctype_digit($response['headers'][$name])
                    ? '<seconds>'
                    : $response['headers'][$name];
            }
        }
        // An empty body's Content-Type is PHP's default_mimetype, not the app's.
        if ($body === '') {
            unset($headers['content-type']);
        }
        ksort($headers);

        $described = ['status' => $response['status'], 'headers' => $headers];
        $isJson = str_contains($headers['content-type'] ?? '', 'json') && json_validate($body);

        if (strlen($body) > self::MAX_INLINE_BODY) {
            $described['sha256'] = hash('sha256', $body);
            $described['bytes'] = strlen($body);
            if ($body === file_get_contents($this->app->path('openapi.json'))) {
                $described['same_as'] = 'backend/openapi.json';
            }
        } elseif ($isJson) {
            $described['json'] = self::normalize(json_decode($body, false, 512, JSON_THROW_ON_ERROR));
        } else {
            $described['text'] = $body;
        }

        return $described;
    }

    /**
     * Replace the values that change from run to run with placeholders; see
     * the README for each one. A value that doesn't look as expected is kept,
     * so the recording shows it.
     */
    private static function normalize(mixed $value, ?string $key = null): mixed
    {
        if ($value instanceof stdClass) {
            $object = new stdClass();
            foreach (get_object_vars($value) as $name => $item) {
                $object->{$name} = self::normalize($item, (string) $name);
            }

            return $object;
        }
        if (is_array($value)) {
            return array_map(static fn(mixed $item): mixed => self::normalize($item), $value);
        }

        return match (true) {
            $key === 'token' && is_string($value) && preg_match('/^[0-9a-f]{64}$/', $value) === 1 => '<token>',
            $key === 'retry_after' && is_int($value) && $value > 0 => '<seconds>',
            $key === 'execution_time' && (is_int($value) || is_float($value)) => '<seconds>',
            $key === 'generated_at' && is_string($value) && abs((int) strtotime($value) - time()) < 3600 => '<now>',
            $key === 'report_file' && $value === 'dupes-' . date('Y-m-d') . '.json' => 'dupes-<today>.json',
            $key === 'file_time' && is_int($value) && abs($value - time()) < 3600 => '<now>',
            default => $value,
        };
    }

    /** @param array<string, mixed> $array */
    private static function string(array $array, string $key): string
    {
        $value = $array[$key] ?? null;
        if (!is_string($value)) {
            throw new RuntimeException("Expected a string at '{$key}'.");
        }

        return $value;
    }

    public static function encode(mixed $value): string
    {
        return json_encode($value, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION | JSON_THROW_ON_ERROR);
    }
}
