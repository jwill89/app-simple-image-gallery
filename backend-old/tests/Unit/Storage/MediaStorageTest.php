<?php

declare(strict_types=1);

namespace Gallery\Tests\Unit\Storage;

use Gallery\Storage\MediaStorage;
use Gallery\Structure\Media;
use Gallery\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(MediaStorage::class)]
#[CoversClass(Media::class)]
final class MediaStorageTest extends DatabaseTestCase
{
    private function insert(
        MediaStorage $storage,
        string $name,
        string $hash,
        string $type = 'image',
        int $time = 1000
    ): Media {
        $media = new Media()
            ->setMediaType($type)
            ->setFileName($name)
            ->setHash($hash)
            ->setFileTime($time);
        $storage->store($media);
        return $media;
    }

    public function testStoreInsertsAndAssignsId(): void
    {
        $storage = new MediaStorage(self::makeDb());
        $media = $this->insert($storage, 'a.jpg', 'h1');

        $this->assertGreaterThan(0, $media->media_id);
    }

    public function testRetrieveByIdReturnsStoredMedia(): void
    {
        $storage = new MediaStorage(self::makeDb());
        $media = $this->insert($storage, 'a.jpg', 'h1');

        $fetched = $storage->retrieve($media->media_id);
        $this->assertInstanceOf(Media::class, $fetched);
        $this->assertSame('a.jpg', $fetched->file_name);
        $this->assertSame('h1', $fetched->hash);
    }

    public function testRetrieveUnknownIdReturnsNull(): void
    {
        $this->assertNull(new MediaStorage(self::makeDb())->retrieve(404));
    }

    public function testRetrieveAllOrdersByFileTimeDescending(): void
    {
        $storage = new MediaStorage(self::makeDb());
        $this->insert($storage, 'old.jpg', 'h1', 'image', 100);
        $this->insert($storage, 'new.jpg', 'h2', 'image', 200);

        $all = $storage->retrieve();
        $this->assertCount(2, $all);
        $this->assertSame('new.jpg', $all[0]->file_name);
    }

    public function testRetrieveTotalCount(): void
    {
        $storage = new MediaStorage(self::makeDb());
        $this->assertSame(0, $storage->retrieveTotalCount());

        $this->insert($storage, 'a.jpg', 'h1');
        $this->insert($storage, 'b.jpg', 'h2');
        $this->assertSame(2, $storage->retrieveTotalCount());
    }

    public function testRetrieveForPagePaginatesNewestFirst(): void
    {
        $storage = new MediaStorage(self::makeDb());
        $this->insert($storage, 'a.jpg', 'h1', 'image', 100);
        $this->insert($storage, 'b.jpg', 'h2', 'image', 200);
        $this->insert($storage, 'c.jpg', 'h3', 'image', 300);

        $page1 = $storage->retrieveForPage(1, 2);
        $this->assertCount(2, $page1);
        $this->assertSame('c.jpg', $page1[0]->file_name);

        $page2 = $storage->retrieveForPage(2, 2);
        $this->assertCount(1, $page2);
        $this->assertSame('a.jpg', $page2[0]->file_name);
    }

    public function testRetrieveByFilename(): void
    {
        $storage = new MediaStorage(self::makeDb());
        $this->insert($storage, 'unique.png', 'h1');

        $byName = $storage->retrieveByFilename('unique.png');
        $this->assertNotNull($byName);
        $this->assertSame('h1', $byName->hash);
        $this->assertNull($storage->retrieveByFilename('missing.png'));
    }

    public function testMediaExistsByFileNameOrHash(): void
    {
        $storage = new MediaStorage(self::makeDb());
        $this->insert($storage, 'a.jpg', 'hash-a');

        $this->assertTrue($storage->mediaExistsInDatabase('a.jpg', 'other'));
        $this->assertTrue($storage->mediaExistsInDatabase('other.jpg', 'hash-a'));
        $this->assertFalse($storage->mediaExistsInDatabase('none.jpg', 'none'));
    }

    public function testRetrieveIdByHash(): void
    {
        $storage = new MediaStorage(self::makeDb());
        $media = $this->insert($storage, 'a.jpg', 'hash-z');

        $this->assertSame($media->media_id, $storage->retrieveIdByHash('hash-z'));
        $this->assertNull($storage->retrieveIdByHash('nope'));
    }

    public function testRetrieveByIdsFiltersEmptyAndNonPositive(): void
    {
        $storage = new MediaStorage(self::makeDb());
        $a = $this->insert($storage, 'a.jpg', 'h1', 'image', 100);
        $b = $this->insert($storage, 'b.jpg', 'h2', 'image', 200);

        $this->assertSame([], $storage->retrieveByIds([]));
        $this->assertSame([], $storage->retrieveByIds([0, -5]));

        $result = $storage->retrieveByIds([$a->media_id, $b->media_id, 9999]);
        $this->assertCount(2, $result);
        $this->assertSame('b.jpg', $result[0]->file_name);
    }

    public function testRetrieveSummaryReturnsLightweightRows(): void
    {
        $storage = new MediaStorage(self::makeDb());
        $this->insert($storage, 'a.jpg', 'h1', 'image');
        $this->insert($storage, 'v.mp4', 'h2', 'video');

        $all = $storage->retrieveSummary();
        $this->assertCount(2, $all);
        $this->assertSame(['media_id', 'media_type', 'file_name', 'hash'], array_keys($all[0]));

        $videos = $storage->retrieveSummary('video');
        $this->assertCount(1, $videos);
        $this->assertSame('v.mp4', $videos[0]['file_name']);
    }

    public function testUpdateMetadata(): void
    {
        $storage = new MediaStorage(self::makeDb());
        $media = $this->insert($storage, 'a.jpg', 'h1');

        $media->setWidth(1920)->setHeight(1080)->setDuration(0.0)->setFileSize(5000);
        $this->assertTrue($storage->updateMetadata($media));

        $fetched = $storage->retrieve($media->media_id);
        $this->assertNotNull($fetched);
        $this->assertSame(1920, $fetched->width);
        $this->assertSame(5000, $fetched->file_size);
    }

    public function testDeleteRemovesRow(): void
    {
        $storage = new MediaStorage(self::makeDb());
        $media = $this->insert($storage, 'a.jpg', 'h1');

        $this->assertTrue($storage->delete($media));
        $this->assertNull($storage->retrieve($media->media_id));
        $this->assertSame(0, $storage->retrieveTotalCount());
    }

    public function testRetrieveUntaggedForPageExcludesTaggedMedia(): void
    {
        $db = self::makeDb();
        $storage = new MediaStorage($db);
        $tagged = $this->insert($storage, 'tagged.jpg', 'h1');
        $this->insert($storage, 'untagged.jpg', 'h2');

        $db->exec("INSERT INTO tag_categories (category_name) VALUES ('General')");
        $db->exec("INSERT INTO tags (category_id, tag_name) VALUES (1, 'sky')");
        $db->prepare('INSERT INTO media_tags (media_id, tag_id) VALUES (:m, 1)')
            ->execute([':m' => $tagged->media_id]);

        $result = $storage->retrieveUntaggedForPage(1, 10);
        $this->assertCount(1, $result);
        $this->assertSame('untagged.jpg', $result[0]->file_name);
    }
}
