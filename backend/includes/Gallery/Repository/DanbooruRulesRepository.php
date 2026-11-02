<?php

namespace Gallery\Repository;

use PDO;

/**
 * DanbooruRulesRepository
 *
 * Persistence for the danbooru_category_map and danbooru_tag_map tables. This
 * unifies what used to be the DanbooruRulesCollection (public API) and
 * DanbooruRulesStorage (SQL) layers, which were a 1:1 pass-through.
 */
class DanbooruRulesRepository
{
    private const string CATEGORY_MAP_TABLE = 'danbooru_category_map';
    private const string TAG_MAP_TABLE = 'danbooru_tag_map';

    public function __construct(private readonly PDO $db)
    {
    }

    // ── Category Map ────────────────────────────────────────

    /**
     * Retrieves all category mappings (joined with gallery category name).
     *
     * @return list<array<string, mixed>>
     */
    public function getCategoryMappings(): array
    {
        $sql = "SELECT m.danbooru_category_id, m.danbooru_category_name, m.gallery_category_id,
                       c.category_name AS gallery_category_name
                FROM " . self::CATEGORY_MAP_TABLE . " m
                LEFT JOIN tag_categories c ON c.category_id = m.gallery_category_id
                ORDER BY m.danbooru_category_id";
        $stmt = $this->db->query($sql);
        // fetchAll(FETCH_ASSOC) is typed as plain array by PHPStan; assert the row shape.
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    /**
     * Adds or replaces a category mapping.
     */
    #[\NoDiscard('a false return signals the mapping was not saved')]
    public function saveCategoryMapping(int $danbooruCategoryId, string $danbooruCategoryName, int $galleryCategoryId): bool
    {
        $sql = "INSERT OR REPLACE INTO " . self::CATEGORY_MAP_TABLE
             . " (danbooru_category_id, danbooru_category_name, gallery_category_id)"
             . " VALUES (:dcid, :dcname, :gcid)";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([
            ':dcid' => $danbooruCategoryId,
            ':dcname' => $danbooruCategoryName,
            ':gcid' => $galleryCategoryId,
        ]);
    }

    /**
     * Deletes a category mapping.
     */
    #[\NoDiscard('a false return signals the mapping was not deleted')]
    public function deleteCategoryMapping(int $danbooruCategoryId): bool
    {
        $stmt = $this->db->prepare("DELETE FROM " . self::CATEGORY_MAP_TABLE . " WHERE danbooru_category_id = :dcid");
        return $stmt->execute([':dcid' => $danbooruCategoryId]);
    }

    /**
     * @return array<int, int> danbooru_category_id => gallery_category_id
     */
    public function getCategoryMapArray(): array
    {
        $stmt = $this->db->query(
            "SELECT danbooru_category_id, gallery_category_id FROM " . self::CATEGORY_MAP_TABLE
        );
        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $map[(int) $row['danbooru_category_id']] = (int) $row['gallery_category_id'];
        }
        return $map;
    }

    /**
     * Builds the full category map as
     * danbooru_category_id => ['gallery_category_id' => int, 'field' => string].
     * 'field' is the Danbooru API field derived from the category name.
     *
     * @return array<int, array{gallery_category_id: int, field: string}>
     */
    public function getCategoryMapWithFields(): array
    {
        $stmt = $this->db->query(
            "SELECT danbooru_category_id, danbooru_category_name, gallery_category_id FROM " . self::CATEGORY_MAP_TABLE
        );
        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $map[(int) $row['danbooru_category_id']] = [
                'gallery_category_id' => (int) $row['gallery_category_id'],
                'field' => 'tag_string_' . strtolower($row['danbooru_category_name']),
            ];
        }
        return $map;
    }

    // ── Tag Name Map ────────────────────────────────────────

    /**
     * Retrieves all tag name mappings.
     *
     * @return list<array<string, mixed>>
     */
    public function getTagMappings(): array
    {
        $sql = "SELECT id, danbooru_tag, gallery_tag FROM " . self::TAG_MAP_TABLE . " ORDER BY danbooru_tag";
        $stmt = $this->db->query($sql);
        // fetchAll(FETCH_ASSOC) is typed as plain array by PHPStan; assert the row shape.
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    /**
     * Adds a tag name mapping and returns its ID.
     */
    #[\NoDiscard('the returned id is 0 on failure and should be checked')]
    public function addTagMapping(string $danbooruTag, string $galleryTag): int
    {
        $sql = "INSERT INTO " . self::TAG_MAP_TABLE . " (danbooru_tag, gallery_tag) VALUES (:dt, :gt)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute([':dt' => $danbooruTag, ':gt' => $galleryTag]);
        return (int) $this->db->lastInsertId();
    }

    /**
     * Updates a tag name mapping.
     */
    #[\NoDiscard('a false return signals the mapping was not updated')]
    public function updateTagMapping(int $id, string $danbooruTag, string $galleryTag): bool
    {
        $sql = "UPDATE " . self::TAG_MAP_TABLE . " SET danbooru_tag = :dt, gallery_tag = :gt WHERE id = :id";
        $stmt = $this->db->prepare($sql);
        return $stmt->execute([':dt' => $danbooruTag, ':gt' => $galleryTag, ':id' => $id]);
    }

    /**
     * Deletes a tag name mapping.
     */
    #[\NoDiscard('a false return signals the mapping was not deleted')]
    public function deleteTagMapping(int $id): bool
    {
        $stmt = $this->db->prepare("DELETE FROM " . self::TAG_MAP_TABLE . " WHERE id = :id");
        return $stmt->execute([':id' => $id]);
    }

    /**
     * Checks if a danbooru tag mapping already exists (optionally excluding an ID).
     */
    public function tagMappingExists(string $danbooruTag, int $excludeId = 0): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM " . self::TAG_MAP_TABLE . " WHERE danbooru_tag = :dt AND id != :eid"
        );
        $stmt->execute([':dt' => $danbooruTag, ':eid' => $excludeId]);
        return (bool) $stmt->fetchColumn();
    }

    /**
     * @return array<string, string> danbooru_tag => gallery_tag
     */
    public function getTagMapArray(): array
    {
        $stmt = $this->db->query("SELECT danbooru_tag, gallery_tag FROM " . self::TAG_MAP_TABLE);
        $map = [];
        while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
            $map[$row['danbooru_tag']] = $row['gallery_tag'];
        }
        return $map;
    }
}
