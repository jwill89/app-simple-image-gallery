<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

// Rector keeps the code on current PHP idioms and strips dead code
// (`composer rector` to apply, `composer rector:check` — the CI step — to list).
return RectorConfig::configure()
    ->withPaths([
        __DIR__ . '/api',
        __DIR__ . '/bin',
        __DIR__ . '/db',
        __DIR__ . '/includes',
        __DIR__ . '/scripts',
        __DIR__ . '/tests',
        __DIR__ . '/index.php',
        __DIR__ . '/phinx.php',
    ])
    ->withPhpSets(php85: true)
    ->withPreparedSets(deadCode: true);
