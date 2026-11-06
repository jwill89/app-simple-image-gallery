<?php

declare(strict_types=1);

use Rector\Config\RectorConfig;

// The shared Rector set (shared-tooling's lint/php: no dead code), current PHP idioms for
// the PHP composer.json requires, and rector-laravel's rules for the installed Laravel
// version. `composer rector` applies, `composer rector:check` (the CI step) lists.
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
    ->withSets([__DIR__ . '/../shared/lint/php/rector-set.php'])
    ->withPhpSets(php85: true)
    ->withComposerBased(laravel: true);
