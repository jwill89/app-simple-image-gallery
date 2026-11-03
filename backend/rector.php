<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

// Current PHP idioms and no dead code, as in the Slim backend's rector.php, plus
// rector-laravel's rules for the installed Laravel version. `composer rector` applies,
// `composer rector:check` (the CI step) lists.
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/app',
        __DIR__ . '/bootstrap/app.php',
        __DIR__ . '/config',
        __DIR__ . '/database',
        __DIR__ . '/public/index.php',
        __DIR__ . '/routes',
        __DIR__ . '/tests',
    ])
    ->withPhpSets(php85: true)
    ->withPreparedSets(deadCode: true)
    ->withComposerBased(laravel: true);
