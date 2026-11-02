<?php

namespace Gallery\Repository;

use PDO;
use Gallery\Structure\Tag;
use Gallery\Structure\Media;

/**
 * TagRepository
 *
 * Persistence for tags and the unified media_tags junction table. This unifies
 * what used to be the TagCollection (public API) and TagStorage (SQL) layers:
 * the collection was a 1:1 pass-through, so the two are now one class exposing
 * the clean collection-style verbs directly over PDO.
 */
class TagRepository
{
    private const string MAIN_TABLE = 'tags';
    private const string MEDIA_TAG_TABLE = 'media_tags';
    private const string CATEGORIES_TABLE = 'tag_categories';
    private const string IMPLICATIONS_TABLE = 'tag_implications';

    private const string OBJ_CLASS = Tag::class;

    public function __construct(private readonly PDO $db)
    {
    }

    /**
     * Gets a tag by ID.
     */
    public function get(int $tag_id): ?Tag
    {
        $sql = "SELECT * FROM " . self::MAIN_TABLE . " WHERE tag_id = :tag_id ORDER BY tag_name ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':tag_id', $tag_id, PDO::PARAM_INT);
        $stmt->execute();
        $tags = $stmt->fetchAll(PDO::FETCH_CLASS, self::OBJ_CLASS);

        return count($tags) === 1 ? $tags[0] : null;
    }

    /**
     * Gets all tags, ordered by name.
     *
     * @return Tag[]
     */
    public function getAll(): array
    {
        $sql = "SELECT * FROM " . self::MAIN_TABLE . " ORDER BY tag_name ASC";
        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_CLASS, self::OBJ_CLASS);
    }

    /**
     * Gets a tag by name, or null if none exists.
     */
    public function getByName(string $tag_name): ?Tag
    {
        $sql = "SELECT * FROM " . self::MAIN_TABLE . " WHERE tag_name = :tag_name";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':tag_name', $tag_name, PDO::PARAM_STR);
        $stmt->execute();
        $stmt->setFetchMode(PDO::FETCH_CLASS, self::OBJ_CLASS);
        $tag = $stmt->fetch();

        return $tag instanceof Tag ? $tag : null;
    }

    /**
     * Resolves an array of tag names to their IDs in a single query.
     * Names with no matching tag are omitted. Matching is case-insensitive
     * (tag_name is COLLATE NOCASE). Returned IDs are de-duplicated.
     *
     * @param string[] $tag_names
     * @return int[] Matched tag IDs (order not guaranteed).
     */
    public function getIdsByNames(array $tag_names): array
    {
        $names = array_values(array_filter(array_map(trim(...), $tag_names), fn($n) => $n !== ''));
        if ($names === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($names), '?'));
        $sql = "SELECT tag_id FROM " . self::MAIN_TABLE . " WHERE tag_name IN ($placeholders)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($names);

        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Returns the subset of the given tag IDs that actually exist, in a single
     * query. Used to validate a batch of tag IDs without an N+1 lookup.
     *
     * @param int[] $tag_ids
     * @return int[] Existing tag IDs (de-duplicated; order not guaranteed).
     */
    public function getExistingIds(array $tag_ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map(intval(...), $tag_ids), fn($id) => $id > 0)));
        if ($ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $sql = "SELECT tag_id FROM " . self::MAIN_TABLE . " WHERE tag_id IN ($placeholders)";
        $stmt = $this->db->prepare($sql);
        $stmt->execute($ids);

        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Gets all tags for a given media item.
     *
     * @return Tag[]
     */
    public function getTagsForMedia(Media $media): array
    {
        $sql = "SELECT tt.* FROM " . self::MAIN_TABLE . " tt
                    LEFT JOIN " . self::MEDIA_TAG_TABLE . " mt USING (tag_id)
                    LEFT JOIN " . self::CATEGORIES_TABLE . " tc ON tc.category_id = tt.category_id
                    WHERE mt.media_id = :media_id
                    ORDER BY tc.sort_order ASC, tt.tag_name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':media_id', $media->media_id, PDO::PARAM_INT);
        $stmt->execute();

        return $stmt->fetchAll(PDO::FETCH_CLASS, self::OBJ_CLASS);
    }

    /**
     * Gets all tags with category and usage counts for the tags page.
     *
     * @return list<array<string, mixed>>
     */
    public function getAllForPage(): array
    {
        $sql = "SELECT t.tag_id, t.tag_name, t.category_id, tc.category_name,
                COALESCE(mc.media_count, 0) AS media_count,
                COALESCE(imp.implication_count, 0) AS implication_count
             FROM " . self::MAIN_TABLE . " t
             LEFT JOIN " . self::CATEGORIES_TABLE . " tc USING (category_id)
             LEFT JOIN (SELECT tag_id, COUNT(*) AS media_count FROM " . self::MEDIA_TAG_TABLE . " GROUP BY tag_id) mc ON mc.tag_id = t.tag_id
             LEFT JOIN (SELECT tag_id, COUNT(*) AS implication_count FROM " . self::IMPLICATIONS_TABLE . " GROUP BY tag_id) imp ON imp.tag_id = t.tag_id
             ORDER BY tc.sort_order ASC, t.tag_name ASC";

        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        // fetchAll(FETCH_ASSOC) is typed as plain array by PHPStan; assert the row shape.
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    /**
     * Gets the total number of tags.
     */
    public function totalTags(): int
    {
        $sql = "SELECT COUNT(*) FROM " . self::MAIN_TABLE;
        $stmt = $this->db->prepare($sql);
        $stmt->execute();

        return (int) $stmt->fetchColumn();
    }

    /**
     * Checks whether a tag exists by name (case-insensitive).
     */
    public function tagExists(string $tag_name): bool
    {
        $sql = "SELECT 1 FROM " . self::MAIN_TABLE . " WHERE tag_name = :tag_name LIMIT 1";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':tag_name', $tag_name, PDO::PARAM_STR);
        $stmt->execute();

        return (int) $stmt->fetchColumn() === 1;
    }

    /**
     * Adds tags to a media item, resolving implications transitively.
     *
     * @param int[] $tag_ids
     */
    public function addTagsToMedia(Media $media, array $tag_ids): bool
    {
        $all_tag_ids = $this->resolveImpliedTagIds($tag_ids);

        if ($all_tag_ids === []) {
            return true;
        }

        $sql = "INSERT OR IGNORE INTO " . self::MEDIA_TAG_TABLE . " (media_id, tag_id) VALUES (:media_id, :tag_id)";
        $media_id = $media->media_id;

        // Wrap the batch insert in a transaction so a mid-loop failure can't
        // leave the media item with a partial set of (implied) tags applied.
        $this->db->beginTransaction();
        try {
            $stmt = $this->db->prepare($sql);
            foreach ($all_tag_ids as $tag_id) {
                $stmt->execute([':media_id' => $media_id, ':tag_id' => $tag_id]);
            }
            $this->db->commit();
            return true;
        } catch (\Throwable) {
            $this->db->rollBack();
            return false;
        }
    }

    /**
     * Removes a tag from a media item.
     */
    #[\NoDiscard('a false return signals the tag was not removed')]
    public function removeTagFromMedia(Media $media, Tag $tag): bool
    {
        $sql = "DELETE FROM " . self::MEDIA_TAG_TABLE . " WHERE media_id = :media_id AND tag_id = :tag_id";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':media_id', $media->media_id, PDO::PARAM_INT);
        $stmt->bindValue(':tag_id', $tag->tag_id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Saves a tag (insert or update) and returns its ID.
     */
    public function save(Tag $tag): int
    {
        $isNew = $tag->tag_id === 0;

        if ($isNew) {
            $sql = "INSERT INTO " . self::MAIN_TABLE . " (category_id, tag_name) VALUES (:category_id, :tag_name)";
        } else {
            $sql = "UPDATE " . self::MAIN_TABLE . " SET category_id = :category_id, tag_name = :tag_name WHERE tag_id = :tag_id";
        }

        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':category_id', $tag->category_id, PDO::PARAM_INT);
        $stmt->bindValue(':tag_name', $tag->tag_name, PDO::PARAM_STR);

        if (!$isNew) {
            $stmt->bindValue(':tag_id', $tag->tag_id, PDO::PARAM_INT);
        }

        if ($stmt->execute() && $isNew) {
            $tag->setTagId((int) $this->db->lastInsertId());
        }

        return $tag->tag_id;
    }

    /**
     * Deletes a tag.
     */
    #[\NoDiscard('a false return signals the tag was not deleted')]
    public function delete(Tag $tag): bool
    {
        $sql = "DELETE FROM " . self::MAIN_TABLE . " WHERE tag_id = :tag_id";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':tag_id', $tag->tag_id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    // ========================================================================
    // Tag Implications
    // ========================================================================

    /**
     * @return list<array<string, mixed>>
     */
    public function getAllImplications(): array
    {
        $sql = "SELECT ti.tag_id, t1.tag_name, ti.implied_tag_id, t2.tag_name AS implied_tag_name
                FROM " . self::IMPLICATIONS_TABLE . " ti
                JOIN " . self::MAIN_TABLE . " t1 ON ti.tag_id = t1.tag_id
                JOIN " . self::MAIN_TABLE . " t2 ON ti.implied_tag_id = t2.tag_id
                ORDER BY t1.tag_name ASC, t2.tag_name ASC";

        $stmt = $this->db->query($sql);
        // fetchAll(FETCH_ASSOC) is typed as plain array by PHPStan; assert the row shape.
        /** @var list<array<string, mixed>> $rows */
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        return $rows;
    }

    public function addImplication(int $tag_id, int $implied_tag_id): bool
    {
        if ($tag_id === $implied_tag_id) {
            return false;
        }

        $resolved = $this->resolveImpliedTagIds([$implied_tag_id]);
        if (in_array($tag_id, $resolved, true)) {
            return false;
        }

        $sql = "INSERT OR IGNORE INTO " . self::IMPLICATIONS_TABLE . " (tag_id, implied_tag_id) VALUES (:tag_id, :implied_tag_id)";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':tag_id', $tag_id, PDO::PARAM_INT);
        $stmt->bindValue(':implied_tag_id', $implied_tag_id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    public function removeImplication(int $tag_id, int $implied_tag_id): bool
    {
        $sql = "DELETE FROM " . self::IMPLICATIONS_TABLE . " WHERE tag_id = :tag_id AND implied_tag_id = :implied_tag_id";
        $stmt = $this->db->prepare($sql);
        $stmt->bindValue(':tag_id', $tag_id, PDO::PARAM_INT);
        $stmt->bindValue(':implied_tag_id', $implied_tag_id, PDO::PARAM_INT);

        return $stmt->execute();
    }

    /**
     * Resolves the full transitive implication closure for a set of seed tag IDs.
     *
     * @param int[] $tag_ids
     * @return int[]
     */
    public function resolveImpliedTagIds(array $tag_ids): array
    {
        $seeds = array_values(array_unique(array_map(intval(...), $tag_ids)));
        if ($seeds === []) {
            return [];
        }

        // Resolve the full transitive implication closure in a single recursive
        // query instead of issuing one query per node (BFS). Using UNION (not
        // UNION ALL) de-duplicates visited ids, which also terminates cycles.
        $valuesPlaceholders = implode(',', array_fill(0, count($seeds), '(?)'));
        $sql = "WITH RECURSIVE closure(id) AS (
                    VALUES {$valuesPlaceholders}
                    UNION
                    SELECT ti.implied_tag_id
                    FROM " . self::IMPLICATIONS_TABLE . " ti
                    JOIN closure c ON ti.tag_id = c.id
                )
                SELECT id FROM closure";

        $stmt = $this->db->prepare($sql);
        $stmt->execute($seeds);

        return array_map(intval(...), $stmt->fetchAll(PDO::FETCH_COLUMN));
    }

    /**
     * Migrates all usages of one tag to another (unified media_tags table).
     */
    #[\NoDiscard('a false return signals the migration was rolled back')]
    public function migrateTag(Tag $sourceTag, Tag $targetTag): bool
    {
        $sourceId = $sourceTag->tag_id;
        $targetId = $targetTag->tag_id;

        $this->db->beginTransaction();

        try {
            // Add target tag where source exists and target doesn't
            $sql = "INSERT OR IGNORE INTO " . self::MEDIA_TAG_TABLE . " (media_id, tag_id)
                    SELECT media_id, :target_id FROM " . self::MEDIA_TAG_TABLE . " WHERE tag_id = :source_id";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':target_id', $targetId, PDO::PARAM_INT);
            $stmt->bindValue(':source_id', $sourceId, PDO::PARAM_INT);
            $stmt->execute();

            // Remove source tag
            $sql = "DELETE FROM " . self::MEDIA_TAG_TABLE . " WHERE tag_id = :source_id";
            $stmt = $this->db->prepare($sql);
            $stmt->bindValue(':source_id', $sourceId, PDO::PARAM_INT);
            $stmt->execute();

            $this->db->commit();
            return true;
        } catch (\Exception) {
            $this->db->rollBack();
            return false;
        }
    }
}
