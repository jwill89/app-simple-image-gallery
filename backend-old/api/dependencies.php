<?php

/**
 * PHP-DI container definitions.
 *
 * Only PDO needs an explicit factory — the shared singleton SQLite connection.
 * The Storage -> Collection -> Controller graph is autowired from constructor
 * type-hints, so nothing else needs to be declared here. The CLI maintenance
 * scripts build a container from this same file, so the API and the scripts
 * share one wiring definition.
 */

declare(strict_types=1);

use Gallery\Core\DatabaseConnection;

return [
    PDO::class => DatabaseConnection::getInstance(...),
];
