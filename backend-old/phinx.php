<?php

return [
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/db/migrations',
        'seeds' => '%%PHINX_CONFIG_DIR%%/db/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinx_migrations',
        'default_environment' => 'production',
        'production' => [
            'adapter' => 'sqlite',
            'name' => './db/gallery',
            'suffix' => '.db',
        ],
    ],
    'version_order' => 'creation',
];
