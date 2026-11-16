<?php

declare(strict_types=1);

namespace Tests\Support;

use JsonException;
use PHPUnit\Framework\AssertionFailedError;
use Symfony\Component\HttpFoundation\Response;

/**
 * Checks an API answer against the committed OpenAPI document (openapi.json): a 2xx
 * must be a status its operation documents, and a JSON body must match the schema
 * documented for it. TestCase::call() runs it on every answer a test gets from /api,
 * so each feature test checks the shapes it reads.
 *
 * It reads the JSON Schema keywords the document uses: $ref, type (a list with
 * "null" for a nullable field), properties, required, additionalProperties, items,
 * enum, allOf, oneOf, minLength, maxLength, pattern, minItems, maxItems, minimum.
 */
final class OpenApiResponses
{
    /** @var array<string, mixed>|null */
    private static ?array $document = null;

    public static function check(string $method, string $path, Response $response): void
    {
        $status = $response->getStatusCode();
        if ($status < 200 || $status >= 300) {
            return;
        }

        $document = self::document();
        $operation = self::operation($document, strtolower($method), $path);
        if ($operation === null) {
            return;
        }

        $responses = is_array($operation['responses'] ?? null) ? $operation['responses'] : [];
        $documented = $responses[(string) $status] ?? null;
        if (!is_array($documented)) {
            throw new AssertionFailedError("{$method} {$path} answered {$status}, which its operation doesn't document.");
        }

        $type = (string) $response->headers->get('Content-Type');
        if (!str_starts_with($type, 'application/json')) {
            return;
        }

        $schema = $documented['content']['application/json']['schema'] ?? null;
        if (!is_array($schema)) {
            throw new AssertionFailedError("{$method} {$path} answered {$status} with JSON, which its operation documents no schema for.");
        }

        try {
            $body = json_decode((string) $response->getContent(), false, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $e) {
            throw new AssertionFailedError("{$method} {$path} answered {$status} with a body that isn't JSON: {$e->getMessage()}");
        }

        $errors = [];
        self::validate($document, $schema, $body, '$', $errors);
        if ($errors !== []) {
            throw new AssertionFailedError("{$method} {$path} answered {$status} with a body its schema refuses:\n  " . implode("\n  ", array_slice($errors, 0, 10)));
        }
    }

    /** @return array<string, mixed> */
    private static function document(): array
    {
        if (self::$document === null) {
            /** @var array<string, mixed> $decoded */
            $decoded = json_decode((string) file_get_contents(dirname(__DIR__, 2) . '/openapi.json'), true, 512, JSON_THROW_ON_ERROR);
            self::$document = $decoded;
        }

        return self::$document;
    }

    /**
     * The operation documented for a method on a path under /api, the trailing slash
     * ignored as the router ignores it; null for a path the document doesn't hold.
     *
     * @param array<string, mixed> $document
     * @return array<string, mixed>|null
     */
    private static function operation(array $document, string $method, string $path): ?array
    {
        $path = '/' . trim((string) preg_replace('#^/api(?=/|$)#', '', $path), '/');
        /** @var array<string, array<string, mixed>> $paths */
        $paths = $document['paths'] ?? [];
        // A fixed segment wins over a parameter in its place (/media/count before
        // /media/{mediaId}), as the router registers them.
        uksort($paths, static fn(string $a, string $b): int => substr_count($a, '{') <=> substr_count($b, '{'));
        foreach ($paths as $template => $operations) {
            $pattern = '#^' . preg_replace('#\\\{[A-Za-z]+\\\}#', '[^/]+', preg_quote($template, '#')) . '$#';
            if (preg_match($pattern, $path) === 1 && is_array($operations[$method] ?? null)) {
                return $operations[$method];
            }
        }

        return null;
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $schema
     * @param list<string> $errors
     */
    private static function validate(array $document, array $schema, mixed $value, string $at, array &$errors): void
    {
        if (isset($schema['$ref']) && is_string($schema['$ref'])) {
            self::validate($document, self::resolve($document, $schema['$ref']), $value, $at, $errors);

            return;
        }

        if (is_array($schema['allOf'] ?? null)) {
            self::validate($document, self::merged($document, $schema), $value, $at, $errors);

            return;
        }

        if (is_array($schema['oneOf'] ?? null)) {
            $matches = 0;
            foreach ($schema['oneOf'] as $part) {
                $found = [];
                if (is_array($part)) {
                    self::validate($document, $part, $value, $at, $found);
                }
                $matches += $found === [] ? 1 : 0;
            }
            if ($matches !== 1) {
                $errors[] = "{$at}: matches {$matches} of oneOf's schemas, not one";
            }
        }

        if (isset($schema['type'])) {
            $types = (array) $schema['type'];
            if (!in_array(self::typeOf($value), $types, true) && !(self::typeOf($value) === 'integer' && in_array('number', $types, true))) {
                $errors[] = "{$at}: is " . self::typeOf($value) . ', not ' . implode(' or ', array_map(strval(...), $types));

                return;
            }
        }

        if (is_array($schema['enum'] ?? null) && !in_array($value, $schema['enum'], true)) {
            $errors[] = "{$at}: " . json_encode($value) . ' is not one of ' . json_encode($schema['enum']);
        }

        if (is_string($value)) {
            self::checkString($schema, $value, $at, $errors);
        }

        if (is_int($value) || is_float($value)) {
            if (isset($schema['minimum']) && is_numeric($schema['minimum']) && $value < $schema['minimum']) {
                $errors[] = "{$at}: {$value} is below {$schema['minimum']}";
            }
        }

        if (is_array($value)) {
            self::checkArray($document, $schema, $value, $at, $errors);
        }

        if ($value instanceof \stdClass) {
            self::checkObject($document, $schema, $value, $at, $errors);
        }
    }

    /**
     * @param array<string, mixed> $schema
     * @param list<string> $errors
     */
    private static function checkString(array $schema, string $value, string $at, array &$errors): void
    {
        $length = mb_strlen($value);
        if (isset($schema['minLength']) && is_int($schema['minLength']) && $length < $schema['minLength']) {
            $errors[] = "{$at}: is shorter than {$schema['minLength']}";
        }
        if (isset($schema['maxLength']) && is_int($schema['maxLength']) && $length > $schema['maxLength']) {
            $errors[] = "{$at}: is longer than {$schema['maxLength']}";
        }
        if (isset($schema['pattern']) && is_string($schema['pattern']) && preg_match('#' . str_replace('#', '\#', $schema['pattern']) . '#u', $value) !== 1) {
            $errors[] = "{$at}: " . json_encode($value) . " doesn't match {$schema['pattern']}";
        }
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $schema
     * @param array<mixed> $value
     * @param list<string> $errors
     */
    private static function checkArray(array $document, array $schema, array $value, string $at, array &$errors): void
    {
        $count = count($value);
        if (isset($schema['minItems']) && is_int($schema['minItems']) && $count < $schema['minItems']) {
            $errors[] = "{$at}: holds fewer than {$schema['minItems']} items";
        }
        if (isset($schema['maxItems']) && is_int($schema['maxItems']) && $count > $schema['maxItems']) {
            $errors[] = "{$at}: holds more than {$schema['maxItems']} items";
        }
        if (is_array($schema['items'] ?? null)) {
            foreach (array_values($value) as $i => $item) {
                self::validate($document, $schema['items'], $item, "{$at}[{$i}]", $errors);
            }
        }
    }

    /**
     * @param array<string, mixed> $document
     * @param array<string, mixed> $schema
     * @param list<string> $errors
     */
    private static function checkObject(array $document, array $schema, \stdClass $value, string $at, array &$errors): void
    {
        $fields = get_object_vars($value);
        foreach (is_array($schema['required'] ?? null) ? $schema['required'] : [] as $name) {
            if (is_string($name) && !array_key_exists($name, $fields)) {
                $errors[] = "{$at}: has no {$name}, which is required";
            }
        }

        /** @var array<string, array<string, mixed>> $properties */
        $properties = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $additional = $schema['additionalProperties'] ?? null;
        foreach ($fields as $name => $field) {
            $name = (string) $name;
            if (isset($properties[$name])) {
                self::validate($document, $properties[$name], $field, "{$at}.{$name}", $errors);
            } elseif (is_array($additional)) {
                self::validate($document, $additional, $field, "{$at}.{$name}", $errors);
            } elseif ($additional === false || ($properties !== [] && $additional === null)) {
                $errors[] = "{$at}: has {$name}, which the schema doesn't document";
            }
        }
    }

    /**
     * An allOf schema as one: its parts' properties and required fields together,
     * with the schema's own keywords beside them, so each part's fields count as
     * documented for the others.
     *
     * @param array<string, mixed> $document
     * @param array<string, mixed> $schema
     * @return array<string, mixed>
     */
    private static function merged(array $document, array $schema): array
    {
        $merged = $schema;
        unset($merged['allOf']);
        $merged['properties'] = is_array($schema['properties'] ?? null) ? $schema['properties'] : [];
        $merged['required'] = is_array($schema['required'] ?? null) ? $schema['required'] : [];
        foreach ($schema['allOf'] as $part) {
            if (!is_array($part)) {
                continue;
            }
            if (isset($part['$ref']) && is_string($part['$ref'])) {
                $part = self::resolve($document, $part['$ref']);
            }
            if (is_array($part['allOf'] ?? null)) {
                $part = self::merged($document, $part);
            }
            $merged['properties'] = [...$merged['properties'], ...(is_array($part['properties'] ?? null) ? $part['properties'] : [])];
            $merged['required'] = [...$merged['required'], ...(is_array($part['required'] ?? null) ? $part['required'] : [])];
            $merged['type'] ??= $part['type'] ?? null;
        }
        if ($merged['type'] === null) {
            unset($merged['type']);
        }

        return $merged;
    }

    /**
     * @param array<string, mixed> $document
     * @return array<string, mixed>
     */
    private static function resolve(array $document, string $ref): array
    {
        $node = $document;
        foreach (explode('/', substr($ref, 2)) as $part) {
            $node = is_array($node) ? ($node[str_replace(['~1', '~0'], ['/', '~'], $part)] ?? null) : null;
        }
        if (!is_array($node)) {
            throw new AssertionFailedError("The OpenAPI document has no {$ref}.");
        }

        return $node;
    }

    private static function typeOf(mixed $value): string
    {
        return match (true) {
            $value === null => 'null',
            is_bool($value) => 'boolean',
            is_int($value) => 'integer',
            is_float($value) => 'number',
            is_string($value) => 'string',
            is_array($value) => 'array',
            default => 'object',
        };
    }
}
