<?php

namespace Gallery\Structure;

use OpenApi\Spec as OAS;

/**
 * Media class
 * Unified structure representing any media item (image or video) in the gallery.
 *
 * Properties use asymmetric visibility (PHP 8.4): reads are public
 * (e.g. $media->file_name), writes go through the fluent setters. private(set)
 * lets PDO's FETCH_CLASS hydrate rows while blocking external mutation.
 */
#[OAS\Schema(schema: 'Media', description: 'A media item (image or video).')]
class Media extends AbstractStructure
{
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $media_id = 0;
    #[OAS\Property(schema: new OAS\Schema(type: 'string', enum: ['image', 'video']))]
    public private(set) string $media_type = 'image'; // 'image' or 'video'
    #[OAS\Property(schema: new OAS\Schema(type: 'string'))]
    public private(set) string $file_name = '';
    #[OAS\Property(schema: new OAS\Schema(type: 'integer', description: 'Unix timestamp the file was ingested.'))]
    public private(set) int $file_time = 0;
    #[OAS\Property(schema: new OAS\Schema(type: 'string', description: 'MD5 hash of the file contents.'))]
    public private(set) string $hash = '';
    #[OAS\Property(schema: new OAS\Schema(type: 'string', description: 'Perceptual fingerprint (images only).'))]
    public private(set) string $bits_fingerprint = '';
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $width = 0;
    #[OAS\Property(schema: new OAS\Schema(type: 'integer'))]
    public private(set) int $height = 0;
    #[OAS\Property(schema: new OAS\Schema(type: 'number', format: 'float', description: 'Duration in seconds; 0 for still images.'))]
    public private(set) float $duration = 0.0;   // seconds; 0 for still images
    #[OAS\Property(schema: new OAS\Schema(type: 'integer', description: 'File size in bytes.'))]
    public private(set) int $file_size = 0;      // bytes

    public function setMediaId(int $media_id): self
    {
        $this->media_id = $media_id;
        return $this;
    }

    public function setMediaType(string $media_type): self
    {
        $this->media_type = $media_type;
        return $this;
    }

    public function setFileName(string $file_name): self
    {
        $this->file_name = $file_name;
        return $this;
    }

    public function setFileTime(int $file_time): self
    {
        $this->file_time = $file_time;
        return $this;
    }

    public function setHash(string $hash): self
    {
        $this->hash = $hash;
        return $this;
    }

    public function setBitsFingerprint(string $bits_fingerprint): self
    {
        $this->bits_fingerprint = $bits_fingerprint;
        return $this;
    }

    public function setWidth(int $width): self
    {
        $this->width = $width;
        return $this;
    }

    public function setHeight(int $height): self
    {
        $this->height = $height;
        return $this;
    }

    public function setDuration(float $duration): self
    {
        $this->duration = $duration;
        return $this;
    }

    public function setFileSize(int $file_size): self
    {
        $this->file_size = $file_size;
        return $this;
    }

    /**
     * Helper: is this an image?
     */
    public function isImage(): bool
    {
        return $this->media_type === 'image';
    }

    /**
     * Helper: is this a video?
     */
    public function isVideo(): bool
    {
        return $this->media_type === 'video';
    }
}
