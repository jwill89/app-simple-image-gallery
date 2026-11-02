<?php

namespace Gallery\Core;

/**
 * Configuration class
 * Loads settings from .env file and environment variables.
 * The .env file is loaded once on first access; explicit environment
 * variables (from the OS, Docker, etc.) always take precedence.
 */
class Configuration
{
    /** Application version (SemVer). Surfaced in the OpenAPI spec and GET /version. */
    public const string VERSION = '4.0.0';

    /** The OpenAPI/HTTP API contract version. Bumped on breaking API changes. */
    public const string API_VERSION = '4.0.0';

    public const int DEFAULT_PER_PAGE = 40;

    /**
     * Duplicate-scan Hamming cutoff (bits out of 64) when DUPLICATE_MAX_DISTANCE
     * is unset. Re-saved and resized copies of gallery images landed 0-5 bits
     * from their originals (2026-11 calibration); the region check, not this,
     * is what keeps variants out.
     */
    public const int DEFAULT_DUPLICATE_MAX_DISTANCE = 6;

    /**
     * Duplicate-scan region-difference ceiling (0-255 levels, see
     * RegionDifference) when DUPLICATE_MAX_REGION_DIFF is unset. On the
     * 2026-11 calibration, re-saved copies of gallery images scored at most
     * 32.7 and the 26 variants dismissed in the UI at least 40.9; this sits
     * between.
     */
    public const float DEFAULT_DUPLICATE_MAX_REGION_DIFF = 36.0;

    /**
     * Highest DUPLICATE_MAX_DISTANCE accepted: a quarter of the 64-bit hash. Past
     * that the LSH bands the scanner needs to keep its no-missed-pairs guarantee
     * shrink to 3 bits or fewer, and nearly every bucket overflows.
     */
    public const int MAX_DUPLICATE_MAX_DISTANCE = 16;

    private static bool $envLoaded = false;

    /**
     * Default allowed CORS origins (used if GALLERY_ALLOWED_ORIGINS is not set).
     */
    private const array DEFAULT_ALLOWED_ORIGINS = [
        'http://localhost',
        'http://localhost:5173',
        'https://localhost',
    ];

    /**
     * Load the .env file into the environment if it hasn't been loaded yet.
     * Searches common locations relative to the script entry point.
     */
    private static function loadEnv(): void
    {
        if (self::$envLoaded) {
            return;
        }
        self::$envLoaded = true;

        // Search for .env in likely locations
        $candidates = [
            __DIR__ . '/../../../.env',  // includes/Gallery/Core/ -> project root
            getcwd() . '/.env',          // current working directory (cron scripts)
        ];

        foreach ($candidates as $path) {
            $resolved = realpath($path);
            if ($resolved !== false && is_file($resolved)) {
                self::parseEnvFile($resolved);
                return;
            }
        }
    }

    /**
     * Parse a .env file and set values via putenv() + $_ENV.
     * Skips blank lines and comments. Does NOT overwrite existing env vars.
     */
    private static function parseEnvFile(string $filePath): void
    {
        $lines = file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);

            // Skip comments
            if ($line === '' || $line[0] === '#') {
                continue;
            }

            // Parse KEY=VALUE
            $eqPos = strpos($line, '=');
            if ($eqPos === false) {
                continue;
            }

            $key   = trim(substr($line, 0, $eqPos));
            $value = trim(substr($line, $eqPos + 1));

            // Strip surrounding quotes
            if (
                (str_starts_with($value, '"') && str_ends_with($value, '"'))
                || (str_starts_with($value, "'") && str_ends_with($value, "'"))
            ) {
                $value = substr($value, 1, -1);
            }

            // Don't overwrite existing environment variables
            if (getenv($key) !== false && getenv($key) !== '') {
                continue;
            }

            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
        }
    }

    /**
     * Get the admin password.
     * Falls back to 'changeme' if not set (development only).
     */
    public static function getAdminPassword(): string
    {
        return self::getEnvVar('GALLERY_ADMIN_PASSWORD') ?? 'changeme';
    }

    /**
     * Whether an admin password has been explicitly configured via
     * GALLERY_ADMIN_PASSWORD. When false, login must be refused so the
     * insecure 'changeme' development default can never grant access.
     */
    public static function isAdminConfigured(): bool
    {
        $password = self::getEnvVar('GALLERY_ADMIN_PASSWORD');
        return $password !== null && $password !== '';
    }

    /**
     * Resolve an environment variable.
     * Loads .env on first call, then checks all standard sources.
     * Apache SetEnv with mod_rewrite prefixes vars with REDIRECT_.
     */
    private static function getEnvVar(string $name): ?string
    {
        self::loadEnv();

        // Check $_ENV
        if (isset($_ENV[$name]) && $_ENV[$name] !== '') {
            return $_ENV[$name];
        }

        // Check getenv()
        $val = getenv($name);
        if ($val !== false && $val !== '') {
            return $val;
        }

        // Check apache_getenv() if available
        if (function_exists('apache_getenv')) {
            $val = apache_getenv($name);
            if ($val !== false && $val !== '') {
                return $val;
            }
        }

        // Check $_SERVER with possible REDIRECT_ prefixes from mod_rewrite
        foreach ($_SERVER as $key => $value) {
            if (preg_match('/^(?:REDIRECT_)*' . preg_quote($name, '/') . '$/', $key) === 1) {
                if ($value !== '') {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * Get the Danbooru API login (username).
     */
    public static function getDanbooruLogin(): string
    {
        return self::getEnvVar('DANBOORU_LOGIN') ?? '';
    }

    /**
     * Get the Danbooru API key.
     */
    public static function getDanbooruApiKey(): string
    {
        return self::getEnvVar('DANBOORU_API_KEY') ?? '';
    }

    /**
     * Get the FontAwesome Kit ID.
     */
    public static function getFontAwesomeKitId(): string
    {
        return self::getEnvVar('FONTAWESOME_KIT_ID') ?? '';
    }

    /**
     * Get the public base URL for the gallery (e.g. "https://gallery.example.com").
     * Used for constructing public image URLs for external services like IQDB.
     * Returns empty string if not configured.
     */
    public static function getGalleryUrl(): string
    {
        return rtrim(self::getEnvVar('GALLERY_URL') ?? '', '/');
    }

    /**
     * Largest Hamming distance at which the duplicate scan still reports a pair
     * (DUPLICATE_MAX_DISTANCE). An unset or invalid value falls back to the default.
     */
    public static function getDuplicateMaxDistance(): int
    {
        return self::parseDuplicateMaxDistance(self::getEnvVar('DUPLICATE_MAX_DISTANCE') ?? '')
            ?? self::DEFAULT_DUPLICATE_MAX_DISTANCE;
    }

    /**
     * A duplicate-scan max distance from text: a whole number from 0 to
     * MAX_DUPLICATE_MAX_DISTANCE, or null if it isn't one. Shared by the env
     * setting and scripts/dupes.php's --max-distance.
     */
    public static function parseDuplicateMaxDistance(string $value): ?int
    {
        $value = trim($value);

        if (preg_match('/^\d{1,2}$/', $value) !== 1) {
            return null;
        }

        $distance = (int) $value;

        return $distance <= self::MAX_DUPLICATE_MAX_DISTANCE ? $distance : null;
    }

    /**
     * Largest region difference (0-255 levels, see RegionDifference) at which
     * the duplicate scan still reports a pair (DUPLICATE_MAX_REGION_DIFF).
     * "off" turns the check off (null); unset or invalid falls back to the default.
     */
    public static function getDuplicateMaxRegionDiff(): ?float
    {
        $value = self::getEnvVar('DUPLICATE_MAX_REGION_DIFF') ?? '';

        if (strtolower(trim($value)) === 'off') {
            return null;
        }

        return self::parseDuplicateMaxRegionDiff($value) ?? self::DEFAULT_DUPLICATE_MAX_REGION_DIFF;
    }

    /**
     * A duplicate-scan region-difference ceiling from text: a number from 0 to
     * 255, or null if it isn't one. Shared by the env setting and
     * scripts/dupes.php's --max-region-diff.
     */
    public static function parseDuplicateMaxRegionDiff(string $value): ?float
    {
        $value = trim($value);

        if (!is_numeric($value)) {
            return null;
        }

        $diff = (float) $value;

        return $diff >= 0.0 && $diff <= 255.0 ? $diff : null;
    }

    /**
     * Get allowed CORS origins.
     * The env var should be a comma-separated list of origins.
     * Falls back to default localhost origins if not set.
     *
     * @return list<string>
     */
    public static function getAllowedOrigins(): array
    {
        $envOrigins = self::getEnvVar('GALLERY_ALLOWED_ORIGINS') ?? '';

        if ($envOrigins !== '') {
            return array_map(trim(...), explode(',', $envOrigins));
        }

        return self::DEFAULT_ALLOWED_ORIGINS;
    }
}
