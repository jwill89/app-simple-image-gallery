<?php

declare(strict_types=1);

namespace Gallery\Tests\Unit\Repository;

use Gallery\Repository\TagCategoryRepository;
use Gallery\Structure\TagCategory;
use Gallery\Tests\Support\DatabaseTestCase;
use PHPUnit\Framework\Attributes\CoversClass;

#[CoversClass(TagCategoryRepository::class)]
#[CoversClass(TagCategory::class)]
final class TagCategoryRepositoryTest extends DatabaseTestCase
{
    private function newCategory(string $name, int $sort = 0, string $color = 'neutral'): TagCategory
    {
        return new TagCategory()
            ->setCategoryName($name)
            ->setColor($color)
            ->setSortOrder($sort);
    }

    public function testSaveInsertsAndAssignsId(): void
    {
        $repo = new TagCategoryRepository(self::makeDb());

        $cat = $this->newCategory('Character', 1, 'teal');
        $id = $repo->save($cat);

        $this->assertGreaterThan(0, $id);
        $this->assertSame($id, $cat->category_id);
    }

    public function testGetByIdReturnsStoredCategory(): void
    {
        $repo = new TagCategoryRepository(self::makeDb());
        $id = $repo->save($this->newCategory('Meta', 0, 'violet'));

        $fetched = $repo->get($id);

        $this->assertInstanceOf(TagCategory::class, $fetched);
        $this->assertSame('Meta', $fetched->category_name);
        $this->assertSame('violet', $fetched->color);
    }

    public function testGetUnknownIdReturnsNull(): void
    {
        $repo = new TagCategoryRepository(self::makeDb());
        $this->assertNull($repo->get(999));
    }

    public function testGetAllReturnsCategoriesOrderedBySortOrder(): void
    {
        $repo = new TagCategoryRepository(self::makeDb());
        $repo->save($this->newCategory('Bravo', 2));
        $repo->save($this->newCategory('Alpha', 1));

        $all = $repo->getAll();

        $this->assertCount(2, $all);
        $this->assertSame('Alpha', $all[0]->category_name);
        $this->assertSame('Bravo', $all[1]->category_name);
    }

    public function testSaveUpdatesExistingRowInPlace(): void
    {
        $repo = new TagCategoryRepository(self::makeDb());
        $cat = $this->newCategory('Old');
        $id = $repo->save($cat);

        $cat->setCategoryName('New')->setColor('amber');
        $repo->save($cat);

        $fetched = $repo->get($id);
        $this->assertNotNull($fetched);
        $this->assertSame('New', $fetched->category_name);
        $this->assertSame('amber', $fetched->color);
        $this->assertCount(1, $repo->getAll());
    }


    public function testGetAllIds(): void
    {
        $repo = new TagCategoryRepository(self::makeDb());
        $id1 = $repo->save($this->newCategory('One'));
        $id2 = $repo->save($this->newCategory('Two'));

        $this->assertSame([$id1, $id2], $repo->getAllIds());
    }

    public function testCountTags(): void
    {
        $db = self::makeDb();
        $repo = new TagCategoryRepository($db);
        $catId = $repo->save($this->newCategory('Cat'));

        $insert = $db->prepare('INSERT INTO tags (category_id, tag_name) VALUES (:c, :n)');
        $insert->execute([':c' => $catId, ':n' => 'tag1']);
        $insert->execute([':c' => $catId, ':n' => 'tag2']);

        $this->assertSame(2, $repo->countTags($catId));
        $this->assertSame(0, $repo->countTags(999));
    }
}
