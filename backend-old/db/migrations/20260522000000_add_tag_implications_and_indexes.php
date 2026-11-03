<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddTagImplicationsAndIndexes extends AbstractMigration
{
    public function change(): void
    {
        // Tag implications table
        $this->table('tag_implications', ['id' => false, 'primary_key' => ['tag_id', 'implied_tag_id']])
            ->addColumn('tag_id', 'integer', ['null' => false])
            ->addColumn('implied_tag_id', 'integer', ['null' => false])
            ->addForeignKey('tag_id', 'tags', 'tag_id', ['delete' => 'CASCADE'])
            ->addForeignKey('implied_tag_id', 'tags', 'tag_id', ['delete' => 'CASCADE'])
            ->create();

        // Dismissed duplicates table
        $this->table('dismissed_duplicates', ['id' => false, 'primary_key' => ['media_id_1', 'media_id_2']])
            ->addColumn('media_id_1', 'integer', ['null' => false])
            ->addColumn('media_id_2', 'integer', ['null' => false])
            ->addColumn('dismissed_at', 'integer', ['null' => false])
            ->addForeignKey('media_id_1', 'media', 'media_id', ['delete' => 'CASCADE'])
            ->addForeignKey('media_id_2', 'media', 'media_id', ['delete' => 'CASCADE'])
            ->create();
    }
}
