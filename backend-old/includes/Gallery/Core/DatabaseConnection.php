<?php

namespace Gallery\Core;

use PDO;

/**
 * DatabaseConnection class
 *
 * This class is responsible for creating a singleton instance of the database connection.
 * It uses the PDO extension to connect to a SQLite database.
 */
class DatabaseConnection
{
    // Access Through Connection
    private static PDO $conn;

    // Prevent New Object Instantiation
    private function __construct()
    {
    }

    // Prevent cloning
    private function __clone()
    {
    }

    /**
     * Get the absolute path to the SQLite database file.
     * Uses __DIR__ to resolve relative to this file's location,
     * so it works regardless of the current working directory.
     */
    private static function getDatabasePath(): string
    {
        // This file is at includes/Gallery/Core/ — go up 3 levels to project root
        return __DIR__ . '/../../../db/gallery.db';
    }

    /**
     * getInstance function
     *
     * This function returns a singleton instance of the database connection.
     * It checks if the connection is already established, and if not, it creates a new PDO instance.
     *
     * @return PDO The PDO instance representing the database connection.
     */
    public static function getInstance(): PDO
    {
        // If the connection isn't set, set it.
        if (!isset(self::$conn)) {
            $db_path = self::getDatabasePath();

            self::$conn = new PDO("sqlite:" . $db_path);
            self::$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

            // Per-connection PRAGMAs. journal_mode (WAL) is persisted in the
            // database header, but re-asserting it is a cheap no-op and keeps
            // setup self-contained. page_size was dropped: it only takes effect
            // on database creation (before any table exists) or a VACUUM, so
            // running it on every connection to an existing DB did nothing.
            self::$conn->exec('PRAGMA journal_mode=WAL');
            self::$conn->exec('PRAGMA foreign_keys=ON');
            self::$conn->exec('PRAGMA synchronous=NORMAL');
            self::$conn->exec('PRAGMA cache_size=-20000');
            self::$conn->exec('PRAGMA temp_store=MEMORY');
            self::$conn->exec('PRAGMA mmap_size=268435456');
            self::$conn->exec('PRAGMA busy_timeout=5000');
        }

        return self::$conn;
    }
}
