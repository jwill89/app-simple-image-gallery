<?php

declare(strict_types=1);

use Phinx\Migration\AbstractMigration;

final class AddMediaMetadata extends AbstractMigration
{
    public function up(): void
    {
        // Basic media metadata extracted at ingest time.
        //  - width/height: pixel dimensions (images and videos)
        //  - duration:     length in seconds (videos/animated GIFs; 0 for images)
        //  - file_size:    file size in bytes
        $this->table('media')
            ->addColumn('width', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('height', 'integer', ['null' => false, 'default' => 0])
            ->addColumn('duration', 'float', ['null' => false, 'default' => 0])
            ->addColumn('file_size', 'integer', ['null' => false, 'default' => 0])
            ->update();
    }

    public function down(): void
    {
        $this->table('media')
            ->removeColumn('width')
            ->removeColumn('height')
            ->removeColumn('duration')
            ->removeColumn('file_size')
            ->update();
    }
}
