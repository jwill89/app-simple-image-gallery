<?php

declare(strict_types=1);

namespace App\OpenApi\Schemas;

use OpenApi\Spec as OAS;

/**
 * The per-file outcome of an upload (POST /media). Fields other than
 * `file_name`/`status` are present depending on the outcome. Doc-only schema.
 */
#[OAS\Schema(component: 'UploadResult', description: 'Per-file upload outcome.')]
class UploadResult
{
    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public string $file_name = '';
    #[OAS\Property(schema: new OAS\Schema(type: 'string', enum: ['success', 'duplicate', 'error']))]
    public string $status = '';
    #[OAS\Property(schema: new OAS\Schema(type: 'integer', description: 'New media ID (on success).'))]
    public int $id = 0;
    #[OAS\Property(schema: new OAS\Schema(type: 'integer', description: 'Existing media ID (on duplicate).'))]
    public int $existing_id = 0;
    #[OAS\Property(schema: new OAS\Schema(type: 'string', description: 'MD5 hash of the file.'))]
    public string $hash = '';
    #[OAS\Property(schema: new OAS\Schema(type: 'string', description: 'Failure reason (on error).'))]
    public string $message = '';
    #[OAS\Property(schema: new OAS\Schema(type: 'boolean', description: 'Whether Danbooru tags were found.'))]
    public bool $tags_found = false;
    #[OAS\Property(schema: new OAS\Schema(type: 'integer', description: 'Number of tags applied from Danbooru.'))]
    public int $tags_applied = 0;
    #[OAS\Property(schema: new OAS\Schema(type: 'string', description: 'Danbooru lookup method used (md5/iqdb).'))]
    public string $tags_method = '';
}
