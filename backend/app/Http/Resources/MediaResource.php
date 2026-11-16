<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Models\Media;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use OpenApi\Spec as OAS;

/** @mixin Media */
#[OAS\Schema(
    component: 'Media',
    description: 'A media item (image or video).',
    required: ['mediaId', 'mediaType', 'fileName', 'fileTime', 'hash', 'bitsFingerprint', 'width', 'height', 'duration', 'fileSize'],
    properties: [
        new OAS\Property(property: 'mediaId', schema: new OAS\Schema(type: 'integer', description: 'The item\'s id.')),
        new OAS\Property(property: 'mediaType', schema: new OAS\Schema(type: 'string', enum: ['image', 'video'], description: 'An animated GIF is a video.')),
        new OAS\Property(property: 'fileName', schema: new OAS\Schema(type: 'string', description: 'The file under /media/full/; its thumbnails are /media/thumbs/<name without extension>.webp and @2x.webp.')),
        new OAS\Property(property: 'fileTime', schema: new OAS\Schema(type: 'string', format: 'date-time', description: 'When it was added (an upload) or last changed (an ingested file), RFC 3339 in UTC; listings are newest first by it.')),
        new OAS\Property(property: 'hash', schema: new OAS\Schema(type: 'string', description: 'MD5 hash of the file contents.')),
        new OAS\Property(property: 'bitsFingerprint', schema: new OAS\Schema(type: 'string', description: 'Perceptual fingerprint, 64 bits as 0s and 1s (images only; empty for videos).')),
        new OAS\Property(property: 'width', schema: new OAS\Schema(type: 'integer', description: 'Width in pixels.')),
        new OAS\Property(property: 'height', schema: new OAS\Schema(type: 'integer', description: 'Height in pixels.')),
        new OAS\Property(property: 'duration', schema: new OAS\Schema(type: 'number', format: 'float', description: 'Duration in seconds; 0 for still images.')),
        new OAS\Property(property: 'fileSize', schema: new OAS\Schema(type: 'integer', description: 'File size in bytes.')),
    ],
)]
final class MediaResource extends JsonResource
{
    /** @return array<string, mixed> */
    public function toArray(Request $request): array
    {
        return [
            'mediaId' => $this->media_id,
            'mediaType' => $this->media_type,
            'fileName' => $this->file_name,
            'fileTime' => $this->file_time,
            'hash' => $this->hash,
            'bitsFingerprint' => $this->bits_fingerprint,
            'width' => $this->width,
            'height' => $this->height,
            'duration' => $this->duration,
            'fileSize' => $this->file_size,
        ];
    }
}
