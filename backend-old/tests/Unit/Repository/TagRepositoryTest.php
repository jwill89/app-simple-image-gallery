<?php

declare(strict_types=1);

namespace Gallery\Tests\Unit\Repository;

use Gallery\Repository\TagRepository;
use Gallery\Structure\Media;
use Gallery\Structure\Tag;
use Gallery\Tests\Support\DatabaseTestCase;
use PDO;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(TagRepository::class)]
#[CoversClass(Tag::class)]
final class TagRepositoryTest extends DatabaseTestCase
{
    private function makeRepository(PDO $db): TagRepository
    {
        return new TagRepository($db);
    }

    private function makeCategory(PDO $db): int
    {
        $db->exec("INSERT INTO tag_categories (category_name) VALUES ('General')");
        return (int) $db->lastInsertId();
    }

    private function makeMedia(PDO $db, string $name = 'a.jpg', string $hash = 'h1'): Media
    {
        $db->prepare("INSERT INTO media (media_type, file_name, file_time, hash) VALUES ('image', :n, 1, :h)")
            ->execute([':n' => $name, ':h' => $hash]);
        return new Media()->setMediaId((int) $db->lastInsertId());
    }

    public function testSaveInsertsAndAssignsId(): void
    {
        $db = self::makeDb();
        $repo = $this->makeRepository($db);
        $catId = $this->makeCategory($db);

        $tag = new Tag()->setCategoryId($catId)->setTagName('forest');
        $id = $repo->save($tag);

        $this->assertGreaterThan(0, $id);
        $this->assertSame($id, $tag->tag_id);
    }

    public function testGetByIdAndByName(): void
    {
        $db = self::makeDb();
        $repo = $this->makeRepository($db);
        $catId = $this->makeCategory($db);
        $tag = new Tag()->setCategoryId($catId)->setTagName('ocean');
        $repo->save($tag);

        $fetched = $repo->get($tag->tag_id);
        $this->assertNotNull($fetched);
        $this->assertSame('ocean', $fetched->tag_name);

        $byName = $repo->getByName('ocean');
        $this->assertNotNull($byName);
        $this->assertSame($tag->tag_id, $byName->tag_id);
        $this->assertNull($repo->getByName('missing'));
    }

    public function testGetIdsByNamesIsCaseInsensitiveAndSkipsBlanksAndUnmatched(): void
    {
        $db = self::makeDb();
        $repo = $this->makeRepository($db);
        $catId = $this->makeCategory($db);
        $a = new Tag()->setCategoryId($catId)->setTagName('forest');
        $repo->save($a);
        $b = new Tag()->setCategoryId($catId)->setTagName('river');
        $repo->save($b);

        $ids = $repo->getIdsByNames([' FOREST ', 'River', 'unknown', '']);
        sort($ids);
        $expected = [$a->tag_id, $b->tag_id];
        sort($expected);

        $this->assertSame($expected, $ids);
        $this->assertSame([], $repo->getIdsByNames([]));
    }

    public function testTagExistsIsCaseInsensitive(): void
    {
        $db = self::makeDb();
        $repo = $this->makeRepository($db);
        $catId = $this->makeCategory($db);
        $repo->save(new Tag()->setCategoryId($catId)->setTagName('sky'));

        $this->assertTrue($repo->tagExists('sky'));
        $this->assertTrue($repo->tagExists('SKY'));
        $this->assertFalse($repo->tagExists('ground'));
    }

    public function testSaveUpdatesExistingTagInPlace(): void
    {
        $db = self::makeDb();
        $repo = $this->makeRepository($db);
        $catId = $this->makeCategory($db);
        $tag = new Tag()->setCategoryId($catId)->setTagName('old');
        $repo->save($tag);

        $tag->setTagName('renamed');
        $repo->save($tag);

        $fetched = $repo->get($tag->tag_id);
        $this->assertNotNull($fetched);
        $this->assertSame('renamed', $fetched->tag_name);
        $this->assertSame(1, $repo->totalTags());
    }

    public function testAddRetrieveAndRemoveTagsForMedia(): void
    {
        $db = self::makeDb();
        $repo = $this->makeRepository($db);
        $catId = $this->makeCategory($db);
        $tagA = new Tag()->setCategoryId($catId)->setTagName('alpha');
        $repo->save($tagA);
        $tagB = new Tag()->setCategoryId($catId)->setTagName('beta');
        $repo->save($tagB);
        $media = $this->makeMedia($db);

        $this->assertTrue($repo->addTagsToMedia($media, [$tagA->tag_id, $tagB->tag_id]));

        $names = array_map(static fn(Tag $t) => $t->tag_name, $repo->getTagsForMedia($media));
        $this->assertCount(2, $names);
        $this->assertContains('alpha', $names);
        $this->assertContains('beta', $names);

        // INSERT OR IGNORE: re-adding does not create duplicates.
        $repo->addTagsToMedia($media, [$tagA->tag_id]);
        $this->assertCount(2, $repo->getTagsForMedia($media));

        $this->assertTrue($repo->removeTagFromMedia($media, $tagA));
        $remaining = $repo->getTagsForMedia($media);
        $this->assertCount(1, $remaining);
        $this->assertSame('beta', $remaining[0]->tag_name);
    }

    public function testAddTagsResolvesImplicationsTransitively(): void
    {
        $db = self::makeDb();
        $repo = $this->makeRepository($db);
        $catId = $this->makeCategory($db);
        $cat = new Tag()->setCategoryId($catId)->setTagName('cat');
        $repo->save($cat);
        $animal = new Tag()->setCategoryId($catId)->setTagName('animal');
        $repo->save($animal);

        // Applying "cat" should also apply the implied "animal".
        $repo->addImplication($cat->tag_id, $animal->tag_id);
        $media = $this->makeMedia($db, 'c.jpg', 'hc');
        $repo->addTagsToMedia($media, [$cat->tag_id]);

        $names = array_map(static fn(Tag $t) => $t->tag_name, $repo->getTagsForMedia($media));
        $this->assertContains('cat', $names);
        $this->assertContains('animal', $names);
    }

    public function testDeleteRemovesTag(): void
    {
        $db = self::makeDb();
        $repo = $this->makeRepository($db);
        $catId = $this->makeCategory($db);
        $tag = new Tag()->setCategoryId($catId)->setTagName('temp');
        $repo->save($tag);

        $this->assertTrue($repo->delete($tag));
        $this->assertNull($repo->get($tag->tag_id));
        $this->assertSame(0, $repo->totalTags());
    }
}
