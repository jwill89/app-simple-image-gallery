<?php

declare(strict_types=1);

use ComposerUnused\ComposerUnused\Configuration\Configuration;
use ComposerUnused\ComposerUnused\Configuration\NamedFilter;

// composer-unused finds a package by the symbols the code uses. The SQLite PDO
// driver has none: DatabaseConnection selects it by the `sqlite:` DSN string.
return static fn(Configuration $config): Configuration => $config
    ->addNamedFilter(NamedFilter::fromString('ext-pdo_sqlite'));
