<?php

namespace Routes\Internal;

use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Gallery\Core\CacheGroup;
use Gallery\Core\ResponseCache;
use Gallery\Repository\TagRepository;
use Gallery\Repository\TagCategoryRepository;
use Gallery\Structure\Tag;
use OpenApi\Spec as OAS;

/**
 * TagController
 * Tag CRUD and tag-to-tag migration. Tag categories and implications live in
 * their own controllers; media-scoped tagging lives in MediaController.
 */
class TagController extends AbstractController
{
    private const int MAX_TAG_NAME_LENGTH = 100;

    public function __construct(private readonly TagRepository $tag_repository, private readonly TagCategoryRepository $category_repository)
    {
        parent::__construct();
    }

    /**
     * GET /tags — All tags (id, name, category). Cached.
     */
    #[OAS\Operation\Get(
        path: '/tags',
        summary: 'List all tags',
        tags: ['Tags'],
        responses: [
            new OAS\Response(response: 200, description: 'All tags', content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/Tag'))),
        ]
    )]
    public function getAllTags(Request $request, Response $response): Response
    {
        return $this->cachedSuccess($response, CacheGroup::Tags, 'all', ResponseCache::TTL_MEDIUM, fn() => $this->tag_repository->getAll());
    }

    /**
     * GET /tags/display — All tags with category and usage/implication counts,
     * for the tags-management page. Cached.
     */
    #[OAS\Operation\Get(
        path: '/tags/display',
        summary: 'List tags with category and usage counts',
        tags: ['Tags'],
        responses: [
            new OAS\Response(response: 200, description: 'Tags with counts', content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/TagListItem'))),
        ]
    )]
    public function getTagListForDisplay(Request $request, Response $response): Response
    {
        return $this->cachedSuccess($response, CacheGroup::Tags, 'display', ResponseCache::TTL_MEDIUM, fn() => $this->tag_repository->getAllForPage());
    }

    /**
     * GET /tags/{tag_id} — A single tag by ID.
     */
    #[OAS\Operation\Get(
        path: '/tags/{tag_id}',
        summary: 'A single tag',
        tags: ['Tags'],
        parameters: [new OAS\Parameter(name: 'tag_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        responses: [
            new OAS\Response(response: 200, description: 'The tag', content: new OAS\MediaType\Json(ref: '#/components/schemas/Tag')),
            new OAS\Response(response: 404, description: 'TagDoesNotExist', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function getTag(Request $request, Response $response, array $args): Response
    {
        $tag_id = $this->intParam($args, 'tag_id', 0);

        if ($tag_id <= 0) {
            return $this->error($response, 'InvalidTagID', 400, 'The tag ID must be a positive number.');
        }

        $tag = $this->tag_repository->get($tag_id);
        return $tag instanceof Tag
            ? $this->success($response, $tag)
            : $this->error($response, 'TagDoesNotExist', 404, 'The requested tag could not be found.');
    }

    /**
     * POST /tags — Create a tag. Body: { tag_name, category_id? (default 1) }.
     * Returns the created tag.
     */
    #[OAS\Operation\Post(
        path: '/tags',
        summary: 'Create a tag',
        tags: ['Tags'],
        security: [new OAS\Security\Requirement(scheme: 'bearerAuth')],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['tag_name'],
                properties: [
                    new OAS\Property(property: 'tag_name', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'category_id', schema: new OAS\Schema(type: 'integer', default: 1)),
                ]
            )
        ),
        responses: [
            new OAS\Response(response: 201, description: 'The created tag', content: new OAS\MediaType\Json(ref: '#/components/schemas/Tag')),
            new OAS\Response(response: 400, description: 'InvalidTagName / TagAlreadyExists', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function addTag(Request $request, Response $response): Response
    {
        $params = $this->parsedBody($request);
        $tag_name = $this->sanitizeTagName($this->stringParam($params, 'tag_name'));
        $tag_category = $this->intParam($params, 'category_id', 1);

        if ($tag_name === '') {
            return $this->error($response, 'InvalidTagName', 400, 'Tag name cannot be empty.');
        }
        if (mb_strlen($tag_name) > self::MAX_TAG_NAME_LENGTH) {
            return $this->error($response, 'TagNameTooLong', 400, 'Tag name must be ' . self::MAX_TAG_NAME_LENGTH . ' characters or fewer.');
        }
        if (!in_array($tag_category, $this->category_repository->getAllIds(), true)) {
            return $this->error($response, 'InvalidCategoryID', 400, 'The selected tag category is not valid.');
        }
        if ($this->tag_repository->getByName($tag_name) instanceof Tag) {
            return $this->error($response, 'TagAlreadyExists', 400, "A tag named \"{$tag_name}\" already exists.");
        }

        $tag = new Tag();
        $tag->setTagName($tag_name)->setCategoryId($tag_category);
        $tag_id = $this->tag_repository->save($tag);

        if ($tag_id === 0) {
            $this->logger->error('Failed to create tag', ['tag_name' => $tag_name]);
            return $this->error($response, 'CouldNotCreateTag', 500, 'The tag could not be created. Please try again.');
        }

        $this->invalidateCache(CacheGroup::Tags);
        $this->logger->info('Tag created', ['tag_id' => $tag_id, 'tag_name' => $tag_name, 'category_id' => $tag_category]);
        return $this->created($response, $tag);
    }

    /**
     * PUT /tags/{tag_id} — Rename and/or recategorize a tag.
     * Body: { tag_name, category_id? (default 1) }. Returns the updated tag.
     */
    #[OAS\Operation\Put(
        path: '/tags/{tag_id}',
        summary: 'Update a tag',
        tags: ['Tags'],
        security: [new OAS\Security\Requirement(scheme: 'bearerAuth')],
        parameters: [new OAS\Parameter(name: 'tag_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['tag_name'],
                properties: [
                    new OAS\Property(property: 'tag_name', schema: new OAS\Schema(type: 'string')),
                    new OAS\Property(property: 'category_id', schema: new OAS\Schema(type: 'integer', default: 1)),
                ]
            )
        ),
        responses: [
            new OAS\Response(response: 200, description: 'The updated tag', content: new OAS\MediaType\Json(ref: '#/components/schemas/Tag')),
            new OAS\Response(response: 404, description: 'TagDoesNotExist', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function editTag(Request $request, Response $response, array $args): Response
    {
        $params = $this->parsedBody($request);
        $tag_id = $this->intParam($args, 'tag_id', 0);
        $tag_name = $this->sanitizeTagName($this->stringParam($params, 'tag_name'));
        $tag_category = $this->intParam($params, 'category_id', 1);

        if ($tag_id <= 0) {
            return $this->error($response, 'InvalidTagID', 400, 'The tag ID must be a positive number.');
        }
        if ($tag_name === '') {
            return $this->error($response, 'InvalidTagName', 400, 'Tag name cannot be empty.');
        }
        if (mb_strlen($tag_name) > self::MAX_TAG_NAME_LENGTH) {
            return $this->error($response, 'TagNameTooLong', 400, 'Tag name must be ' . self::MAX_TAG_NAME_LENGTH . ' characters or fewer.');
        }
        if (!in_array($tag_category, $this->category_repository->getAllIds(), true)) {
            return $this->error($response, 'InvalidCategoryID', 400, 'The selected tag category is not valid.');
        }

        $tag = $this->tag_repository->get($tag_id);
        if (!($tag instanceof Tag)) {
            return $this->error($response, 'TagDoesNotExist', 404, 'The tag to edit could not be found.');
        }

        $tag->setTagName($tag_name)->setCategoryId($tag_category);
        $saved_id = $this->tag_repository->save($tag);

        if ($saved_id === 0) {
            $this->logger->error('Failed to save tag edit', ['tag_id' => $tag_id]);
            return $this->error($response, 'CouldNotSaveTag', 500, 'The tag changes could not be saved. Please try again.');
        }

        $this->invalidateCache(CacheGroup::Tags);
        $this->logger->info('Tag edited', ['tag_id' => $tag_id, 'tag_name' => $tag_name, 'category_id' => $tag_category]);
        return $this->success($response, $tag);
    }

    /**
     * DELETE /tags/{tag_id} — Delete a tag.
     * DELETE /tags/{tag_id}/migrate-to/{target_tag_id} — migrate this tag's media
     * to the target first, then delete this tag.
     */
    #[OAS\Operation\Delete(
        path: '/tags/{tag_id}',
        summary: 'Delete a tag',
        tags: ['Tags'],
        security: [new OAS\Security\Requirement(scheme: 'bearerAuth')],
        parameters: [new OAS\Parameter(name: 'tag_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        responses: [
            new OAS\Response(response: 204, description: 'Deleted'),
            new OAS\Response(response: 404, description: 'TagDoesNotExist', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    #[OAS\Operation\Delete(
        path: '/tags/{tag_id}/migrate-to/{target_tag_id}',
        summary: 'Migrate a tag\'s media to another tag, then delete it',
        tags: ['Tags'],
        security: [new OAS\Security\Requirement(scheme: 'bearerAuth')],
        parameters: [
            new OAS\Parameter(name: 'tag_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer')),
            new OAS\Parameter(name: 'target_tag_id', in: 'path', required: true, description: 'Migrate media to this tag before deleting.', schema: new OAS\Schema(type: 'integer')),
        ],
        responses: [
            new OAS\Response(response: 204, description: 'Migrated and deleted'),
            new OAS\Response(response: 404, description: 'TagDoesNotExist / MigrationTargetDoesNotExist', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function deleteTag(Request $request, Response $response, array $args): Response
    {
        $tag_id = $this->intParam($args, 'tag_id', 0);
        $migrate_to_id = $this->intParam($args, 'target_tag_id', 0);

        if ($tag_id <= 0) {
            return $this->error($response, 'InvalidTagID', 400, 'The tag ID must be a positive number.');
        }

        $tag = $this->tag_repository->get($tag_id);
        if (!($tag instanceof Tag)) {
            return $this->error($response, 'TagDoesNotExist', 404, 'The tag to delete could not be found.');
        }

        if ($migrate_to_id > 0) {
            if ($migrate_to_id === $tag_id) {
                return $this->error($response, 'CannotMigrateToSelf', 400, 'A tag cannot be migrated to itself.');
            }

            $targetTag = $this->tag_repository->get($migrate_to_id);
            if (!($targetTag instanceof Tag)) {
                return $this->error($response, 'MigrationTargetDoesNotExist', 404, 'The migration target tag could not be found.');
            }

            $migrated = $this->tag_repository->migrateTag($tag, $targetTag);
            if (!$migrated) {
                $this->logger->error('Failed to migrate before delete', ['tag_id' => $tag_id, 'target' => $migrate_to_id]);
                return $this->error($response, 'CouldNotMigrateTag', 500, 'Tag migration failed before deletion. Nothing was deleted.');
            }
        }

        $deleted = $this->tag_repository->delete($tag);
        if (!$deleted) {
            $this->logger->error('Failed to delete tag', ['tag_id' => $tag_id]);
            return $this->error($response, 'CouldNotDeleteTag', 500, 'The tag could not be deleted. Please try again.');
        }

        $this->invalidateCache(CacheGroup::Tags, CacheGroup::Media);
        $this->logger->info('Tag deleted', ['tag_id' => $tag_id, 'migrated_to' => $migrate_to_id]);
        return $this->noContent($response);
    }

    /**
     * POST /tags/{tag_id}/migrate — Move all media from this tag to the target,
     * then delete this tag. Body: { target_tag_id }.
     */
    #[OAS\Operation\Post(
        path: '/tags/{tag_id}/migrate',
        summary: 'Migrate a tag into another',
        tags: ['Tags'],
        security: [new OAS\Security\Requirement(scheme: 'bearerAuth')],
        parameters: [new OAS\Parameter(name: 'tag_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        requestBody: new OAS\RequestBody(
            required: true,
            content: new OAS\MediaType\Json(
                required: ['target_tag_id'],
                properties: [new OAS\Property(property: 'target_tag_id', schema: new OAS\Schema(type: 'integer'))]
            )
        ),
        responses: [
            new OAS\Response(response: 200, description: 'Migration result', content: new OAS\MediaType\Json(ref: '#/components/schemas/MigrateResult')),
            new OAS\Response(response: 404, description: 'TagDoesNotExist', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function migrateTag(Request $request, Response $response, array $args): Response
    {
        $source_id = $this->intParam($args, 'tag_id', 0);
        $target_id = $this->intParam($this->parsedBody($request), 'target_tag_id', 0);

        if ($source_id <= 0 || $target_id <= 0) {
            return $this->error($response, 'InvalidTagID', 400, 'Both source and target tag IDs must be positive numbers.');
        }

        if ($source_id === $target_id) {
            return $this->error($response, 'CannotMigrateToSelf', 400, 'A tag cannot be migrated to itself.');
        }

        $sourceTag = $this->tag_repository->get($source_id);
        $targetTag = $this->tag_repository->get($target_id);

        if (!($sourceTag instanceof Tag) || !($targetTag instanceof Tag)) {
            return $this->error($response, 'TagDoesNotExist', 404, 'The source or target tag could not be found.');
        }

        $success = $this->tag_repository->migrateTag($sourceTag, $targetTag);

        if (!$success) {
            $this->logger->error('Failed to migrate tag', ['source' => $source_id, 'target' => $target_id]);
            return $this->error($response, 'CouldNotMigrateTag', 500, 'Tag migration failed. Please try again.');
        }

        $this->invalidateCache(CacheGroup::Tags, CacheGroup::Media);
        $this->logger->info('Tag migrated', ['source' => $source_id, 'target' => $target_id]);
        return $this->success($response, [
            'migrated' => true,
            'source_tag_id' => $source_id,
            'target_tag_id' => $target_id,
        ]);
    }

    /**
     * Normalizes a tag name: trim, lowercase, strip tags/control chars, and
     * drop any leading '-' (which would otherwise read as a search exclusion).
     */
    private function sanitizeTagName(string $tag_name): string
    {
        return $tag_name
            |> trim(...)
            |> (fn(string $s): string => mb_strtolower($s, 'UTF-8'))
            |> strip_tags(...)
            |> (fn(string $s): string => (string) preg_replace('/[\x00-\x1F\x7F]/u', '', $s))
            |> (fn(string $s): string => ltrim($s, '-'));
    }
}
