<?php

/**
 * The backend's code style: `composer lint` checks, `composer lint:fix` fixes, and CI
 * runs the check. The shared rules (PER Coding Style 3.0, pinned so a fixer upgrade
 * can't move the style) and the migration set for the PHP composer.json requires, so
 * the fixer never writes syntax the server can't run.
 */

return new PhpCsFixer\Config()
    ->setRules([
        ...require __DIR__ . '/../shared/lint/php/php-cs-fixer-rules.php',
        '@PHP8x5Migration' => true,
    ])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in([__DIR__ . '/app', __DIR__ . '/bootstrap', __DIR__ . '/config', __DIR__ . '/database', __DIR__ . '/routes', __DIR__ . '/tests'])
            ->notPath('cache')
            ->append([__FILE__, __DIR__ . '/rector.php', __DIR__ . '/public/index.php'])
    )
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
