<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Spec as OAS;

/** @mixin Media */
#[OAS\Schema(component: 'Media', description: 'A media item (image or video).', properties: [
    new OAS\Property(property: 'media_id', schema: new OAS\Schema(type: 'integer')),
    new OAS\Property(property: 'media_type', schema: new OAS\Schema(type: 'string', enum: ['image', 'video'])),
    new OAS\Property(property: 'file_name', schema: new OAS\Schema(type: 'string')),
    new OAS\Property(property: 'file_time', schema: new OAS\Schema(type: 'integer', description: 'Unix timestamp the file was ingested.')),
    new OAS\Property(property: 'hash', schema: new OAS\Schema(type: 'string', description: 'MD5 hash of the file contents.')),
    new OAS\Property(property: 'bits_fingerprint', schema: new OAS\Schema(type: 'string', description: 'Perceptual fingerprint (images only).')),
    new OAS\Property(property: 'width', schema: new OAS\Schema(type: 'integer')),
    new OAS\Property(property: 'height', schema: new OAS\Schema(type: 'integer')),
    new OAS\Property(property: 'duration', schema: new OAS\Schema(type: 'number', format: 'float', description: 'Duration in seconds; 0 for still images.')),
    new OAS\Property(property: 'file_size', schema: new OAS\Schema(type: 'integer', description: 'File size in bytes.')),
])]
final class MediaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'media_id' => $this->media_id,
            'media_type' => $this->media_type,
            'file_name' => $this->file_name,
            'file_time' => $this->file_time,
            'hash' => $this->hash,
            'bits_fingerprint' => $this->bits_fingerprint,
            'width' => $this->width,
            'height' => $this->height,
            'duration' => $this->duration,
            'file_size' => $this->file_size,
        ];
    }
}
