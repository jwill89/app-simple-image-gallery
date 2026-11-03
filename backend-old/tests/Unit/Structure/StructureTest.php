<?php

declare(strict_types=1);

namespace Gallery\Tests\Unit\Structure;

use Gallery\Structure\AbstractStructure;
use Gallery\Structure\Media;
use Gallery\Structure\Tag;
use Gallery\Structure\TagCategory;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(AbstractStructure::class)]
#[CoversClass(Media::class)]
#[CoversClass(Tag::class)]
#[CoversClass(TagCategory::class)]
final class StructureTest extends TestCase
{
    public function testMediaConstructorPopulatesProperties(): void
    {
        $media = new Media([
            'media_id'   => 7,
            'media_type' => 'video',
            'file_name'  => 'clip.mp4',
            'file_time'  => 1700000000,
            'hash'       => 'abc123',
            'width'      => 1920,
            'height'     => 1080,
            'duration'   => 12.5,
            'file_size'  => 2048,
        ]);

        $this->assertSame(7, $media->media_id);
        $this->assertSame('video', $media->media_type);
        $this->assertSame('clip.mp4', $media->file_name);
        $this->assertSame(1700000000, $media->file_time);
        $this->assertSame('abc123', $media->hash);
        $this->assertSame(1920, $media->width);
        $this->assertSame(1080, $media->height);
        $this->assertSame(12.5, $media->duration);
        $this->assertSame(2048, $media->file_size);
        $this->assertTrue($media->isVideo());
        $this->assertFalse($media->isImage());
    }

    public function testMediaDefaults(): void
    {
        $media = new Media();

        $this->assertSame(0, $media->media_id);
        $this->assertSame('image', $media->media_type);
        $this->assertSame('', $media->file_name);
        $this->assertSame(0.0, $media->duration);
        $this->assertSame('', $media->bits_fingerprint);
        $this->assertTrue($media->isImage());
        $this->assertFalse($media->isVideo());
    }

    public function testMediaFluentSetters(): void
    {
        $media = new Media()
            ->setMediaType('image')
            ->setFileName('pic.png')
            ->setHash('deadbeef')
            ->setWidth(800)
            ->setHeight(600)
            ->setFileSize(1234)
            ->setBitsFingerprint('1010');

        $this->assertSame('pic.png', $media->file_name);
        $this->assertSame('deadbeef', $media->hash);
        $this->assertSame(800, $media->width);
        $this->assertSame(600, $media->height);
        $this->assertSame(1234, $media->file_size);
        $this->assertSame('1010', $media->bits_fingerprint);
    }

    public function testUnknownConstructorKeysAreIgnored(): void
    {
        $tag = new Tag(['tag_id' => 5, 'does_not_exist' => 'x']);

        $this->assertSame(5, $tag->tag_id);
        $this->assertObjectNotHasProperty('does_not_exist', $tag);
    }

    public function testTagAccessors(): void
    {
        $tag = new Tag()->setTagId(3)->setCategoryId(2)->setTagName('forest');

        $this->assertSame(3, $tag->tag_id);
        $this->assertSame(2, $tag->category_id);
        $this->assertSame('forest', $tag->tag_name);
    }

    public function testTagCategoryDefaultsAndAccessors(): void
    {
        $cat = new TagCategory();
        $this->assertSame('neutral', $cat->color);
        $this->assertSame(0, $cat->sort_order);

        $cat->setCategoryName('Character')
            ->setColor('teal')
            ->setSortOrder(2)
            ->setDescription('People');

        $this->assertSame('Character', $cat->category_name);
        $this->assertSame('teal', $cat->color);
        $this->assertSame(2, $cat->sort_order);
        $this->assertSame('People', $cat->description);
    }

    public function testJsonSerializeReturnsAllDeclaredProperties(): void
    {
        $cat = new TagCategory(['category_id' => 1, 'category_name' => 'Meta', 'color' => 'purple']);
        $data = $cat->jsonSerialize();

        $this->assertSame(
            ['category_id', 'category_name', 'color', 'description', 'sort_order'],
            array_keys($data)
        );
        $this->assertSame(1, $data['category_id']);
        $this->assertSame('Meta', $data['category_name']);
        $this->assertSame('purple', $data['color']);
    }

    public function testJsonEncodeRoundTrip(): void
    {
        $media = new Media(['media_id' => 9, 'file_name' => 'a.jpg']);
        $decoded = json_decode(json_encode($media, JSON_THROW_ON_ERROR), true);

        $this->assertSame(9, $decoded['media_id']);
        $this->assertSame('a.jpg', $decoded['file_name']);
        $this->assertArrayHasKey('bits_fingerprint', $decoded);
    }

    public function testSetPropertiesUpdatesExistingInstance(): void
    {
        $tag = new Tag(['tag_id' => 1, 'tag_name' => 'old']);
        $tag->setProperties(['tag_name' => 'new', 'category_id' => 4]);

        $this->assertSame('new', $tag->tag_name);
        $this->assertSame(4, $tag->category_id);
        $this->assertSame(1, $tag->tag_id);
    }
}
