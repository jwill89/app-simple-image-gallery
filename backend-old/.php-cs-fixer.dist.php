<?php

/**
 * The backend's one code-style tool: `composer lint` checks, `composer lint:fix`
 * fixes, and CI runs the check.
 *
 * PER Coding Style 3.0, PSR-12's successor, pinned so a fixer upgrade can't move
 * the standard under us. Where PER-CS and this codebase disagree on something
 * cosmetic, the codebase's long-standing habit wins, so adopting the tool didn't
 * restyle working code.
 */

return new PhpCsFixer\Config()
    ->setRules([
        '@PER-CS3x0' => true,
        '@PHP8x5Migration' => true,
        // An empty body (mostly promoted-property constructors) keeps its own lines.
        'single_line_empty_body' => false,
        // A comment naming the next `} elseif` branch sits at that branch's depth
        // (index.php's route table), not inside the branch above it.
        'statement_indentation' => ['stick_comment_to_next_continuous_control_statement' => true],
        'no_unused_imports' => true,
    ])
    ->setFinder(
        PhpCsFixer\Finder::create()
            ->in([__DIR__ . '/api', __DIR__ . '/bin', __DIR__ . '/db', __DIR__ . '/includes', __DIR__ . '/scripts', __DIR__ . '/tests'])
            ->append([__DIR__ . '/index.php', __DIR__ . '/phinx.php', __DIR__ . '/rector.php', __FILE__])
    )
    ->setCacheFile(__DIR__ . '/.php-cs-fixer.cache');
