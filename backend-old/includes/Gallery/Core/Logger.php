<?php

namespace Gallery\Core;

use Monolog\Logger as MonologLogger;
use Monolog\Handler\RotatingFileHandler;
use Monolog\Level;

/**
 * Logger class
 * Provides a singleton PSR-3 compatible logger using Monolog.
 */
class Logger
{
    private static ?MonologLogger $instance = null;

    private const string LOG_PATH = __DIR__ . '/../../../logs/gallery.log';

    private function __construct()
    {
    }

    public static function getInstance(): MonologLogger
    {
        if (self::$instance === null) {
            self::$instance = new MonologLogger('gallery');

            // Ensure logs directory exists
            $logDir = dirname(self::LOG_PATH);
            if (!is_dir($logDir) && !mkdir($logDir, 0o755, true) && !is_dir($logDir)) {
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $logDir));
            }

            // Rotating file handler - keeps 14 days of logs
            self::$instance->pushHandler(
                new RotatingFileHandler(self::LOG_PATH, 14, Level::Info)
            );
        }

        return self::$instance;
    }
}
