<?php

namespace Gallery\Repository;

use PDO;
use Gallery\Structure\TagCategory;
use Gallery\Structure\Tag;

/**
 * TagCategoryRepository
 *
 * Persistence for tag categories. This unifies what used to be the
 * TagCategoryCollection (public API) and TagCategoryStorage (SQL) layers:
 * the collection was a 1:1 pass-through, so the two are now one class that
 * exposes the clean collection-style verbs directly over PDO.
 */
class TagCategoryRepository
{
    private const string MAIN_TABLE = 'tag_categories';
    private const string TAGS_TABLE = 'tags';

    private const string OBJ_CLASS = TagCategory::class;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Gets a tag category by ID.
     */
    public function get(int $category_id): ?TagCategory
    {
        $sql = "SELECT * FROM " . self::MAIN_TABLE . " WHERE category_id = :category_id ORDER BY sort_order ASC, category_name ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':category_id', $category_id, PDO::PARAM_INT);
        $stmt->execute();
        $categories = $stmt->fetchAll(PDO::FETCH_CLASS, self::OBJ_CLASS);

        return count($categories) === 1 ? $categories[0] : null;
    }

    /**
     * Gets all tag categories, ordered by sort order then name.
     *
     * @return TagCategory[]
     */
    public function getAll(): array
    {
        $sql = "SELECT * FROM " . self::MAIN_TABLE . " ORDER BY sort_order ASC, category_name ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_CLASS, self::OBJ_CLASS);
    }


    /**
     * Gets the tags belonging to a category.
     *
     * @return Tag[]
     */
    public function getTagsForCategory(TagCategory $category): array
    {
        $sql = "SELECT t.* FROM " . self::TAGS_TABLE . " t
                LEFT JOIN " . self::MAIN_TABLE . " tc USING (category_id)
                WHERE tc.category_id = :category_id
                ORDER BY t.tag_name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':category_id', $category->category_id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_CLASS, Tag::class);
    }

    /**
     * Saves a tag category (insert or update) and returns its ID.
     */
    public function save(TagCategory $category): int
    {
        if ($category->category_id === 0) {
            $sql = "INSERT INTO " . self::MAIN_TABLE
                 . " (category_name, color, description, sort_order)"
                 . " VALUES (:category_name, :color, :description, :sort_order)";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':category_name', $category->category_name, PDO::PARAM_STR);
            $stmt->bindValue(':color', $category->color, PDO::PARAM_STR);
            $stmt->bindValue(':description', $category->description, PDO::PARAM_STR);
            $stmt->bindValue(':sort_order', $category->sort_order, PDO::PARAM_INT);

            $stmt->execute();
            $category->setCategoryId((int) $this->db->lastInsertId());
        } else {
            $sql = "UPDATE " . self::MAIN_TABLE
                 . " SET category_name = :category_name,"
                 . " color = :color, description = :description, sort_order = :sort_order"
                 . " WHERE category_id = :category_id";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':category_name', $category->category_name, PDO::PARAM_STR);
            $stmt->bindValue(':color', $category->color, PDO::PARAM_STR);
            $stmt->bindValue(':description', $category->description, PDO::PARAM_STR);
            $stmt->bindValue(':sort_order', $category->sort_order, PDO::PARAM_INT);
            $stmt->bindValue(':category_id', $category->category_id, PDO::PARAM_INT);
            $stmt->execute();
        }

        return $category->category_id;
    }

    /**
     * Deletes a tag category.
     */
    #[\NoDiscard('a false return signals the category was not deleted')]
    public function delete(TagCategory $category): bool
    {
        $sql = "DELETE FROM " . self::MAIN_TABLE . " WHERE category_id = :category_id";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':category_id', $category->category_id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Counts the tags belonging to a category.
     */
    public function countTags(int $categoryId): int
    {
        $stmt = $this->db->prepare("SELECT COUNT(*) FROM " . self::TAGS_TABLE . " WHERE category_id = :cid");
        $stmt->bindValue(':cid', $categoryId, PDO::PARAM_INT);
        $stmt->execute();
        return (int) $stmt->fetchColumn();
    }

    /**
     * Returns all valid category IDs.
     *
     * @return int[]
     */
    public function getAllIds(): array
    {
        $stmt = $this->db->query("SELECT category_id FROM " . self::MAIN_TABLE . " ORDER BY category_id");
        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Checks for name/shortcode conflicts, optionally excluding a category ID.
     *
     * @return string[] List of conflicting fields ('name', 'short').
     */
    public function checkConflicts(string $name, int $excludeId = 0): array
    {
        $conflicts = [];
        $stmt = $this->db->prepare(
            "SELECT category_id FROM " . self::MAIN_TABLE . " WHERE category_name = :n COLLATE NOCASE AND category_id != :eid"
        );
        $stmt->execute([':n' => $name, ':eid' => $excludeId]);
        if ($stmt->fetchColumn() !== false) {
            $conflicts[] = 'name';
        }


        return $conflicts;
    }
}
