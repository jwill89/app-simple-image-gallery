<?php

namespace Routes\Internal;

use Slim\Http\ServerRequest as Request;
use Slim\Http\Response;
use Gallery\Core\CacheGroup;
use Gallery\Repository\TagCategoryRepository;
use Gallery\Structure\TagCategory;
use OpenApi\Spec as OAS;

/**
 * TagCategoryController
 * CRUD for tag categories (the `/tag-categories` resource).
 */
class TagCategoryController extends AbstractController
{
    private const int MAX_CATEGORY_NAME_LENGTH = 50;
    /**
     * Colours a tag category may use.
     *
     * Hue names only. These are display values and must never overlap the
     * semantic palette (danger / success / warning / info), which is reserved
     * for state in the UI. Categories previously stored Bulma state names —
     * Artist was `danger` — which made the Artist chip and the delete button
     * the same red. Migration 20260805000000 rewrote the stored values.
     *
     * Keep in sync with `VALID_COLORS` in frontend/src/constants/categories.ts.
     */
    private const array VALID_COLORS = [
        'neutral', 'rose', 'amber', 'emerald', 'sky', 'violet', 'teal', 'orange', 'lime',
    ];

    public function __construct(private readonly TagCategoryRepository $category_repository)
    {
        parent::__construct();
    }

    /**
     * GET /tag-categories — All tag categories.
     */
    #[OAS\Operation\Get(
        path: '/tag-categories',
        summary: 'List tag categories',
        tags: ['Tag Categories'],
        responses: [
            new OAS\Response(response: 200, description: 'All categories', content: new OAS\MediaType\Json(type: 'array', items: new OAS\Schema\Items(ref: '#/components/schemas/TagCategory'))),
        ]
    )]
    public function getCategories(Request $request, Response $response): Response
    {
        return $this->success($response, $this->category_repository->getAll());
    }

    /**
     * POST /tag-categories — Create a tag category. Returns the created category.
     * Body: { category_name, color?, description?, sort_order? }
     */
    #[OAS\Operation\Post(
        path: '/tag-categories',
        summary: 'Create a tag category',
        tags: ['Tag Categories'],
        security: [new OAS\Security\Requirement(scheme: 'bearerAuth')],
        requestBody: new OAS\RequestBody(required: true, content: new OAS\MediaType\Json(
            required: ['category_name'],
            properties: [
                new OAS\Property(property: 'category_name', schema: new OAS\Schema(type: 'string')),
                new OAS\Property(property: 'color', schema: new OAS\Schema(type: 'string')),
                new OAS\Property(property: 'description', schema: new OAS\Schema(type: 'string')),
                new OAS\Property(property: 'sort_order', schema: new OAS\Schema(type: 'integer')),
            ]
        )),
        responses: [
            new OAS\Response(response: 201, description: 'The created category', content: new OAS\MediaType\Json(ref: '#/components/schemas/TagCategory')),
            new OAS\Response(response: 400, description: 'Validation error', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function addCategory(Request $request, Response $response): Response
    {
        $result = $this->buildValidatedCategory($response, $this->parsedBody($request));
        if ($result instanceof Response) {
            return $result;
        }
        $category = $result;

        $id = $this->category_repository->save($category);
        if ($id === 0) {
            return $this->error($response, 'SaveFailed', 500, 'Could not create the category.');
        }

        $this->invalidateCache(CacheGroup::Tags);
        $this->logger->info('Category created', ['category_id' => $id, 'name' => $category->category_name]);
        return $this->created($response, $category);
    }

    /**
     * PUT /tag-categories/{category_id} — Update a tag category.
     * Returns the updated category. Body: same fields as create.
     */
    #[OAS\Operation\Put(
        path: '/tag-categories/{category_id}',
        summary: 'Update a tag category',
        tags: ['Tag Categories'],
        security: [new OAS\Security\Requirement(scheme: 'bearerAuth')],
        parameters: [new OAS\Parameter(name: 'category_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        requestBody: new OAS\RequestBody(required: true, content: new OAS\MediaType\Json(
            required: ['category_name'],
            properties: [
                new OAS\Property(property: 'category_name', schema: new OAS\Schema(type: 'string')),
                new OAS\Property(property: 'color', schema: new OAS\Schema(type: 'string')),
                new OAS\Property(property: 'description', schema: new OAS\Schema(type: 'string')),
                new OAS\Property(property: 'sort_order', schema: new OAS\Schema(type: 'integer')),
            ]
        )),
        responses: [
            new OAS\Response(response: 200, description: 'The updated category', content: new OAS\MediaType\Json(ref: '#/components/schemas/TagCategory')),
            new OAS\Response(response: 404, description: 'CategoryNotFound', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function editCategory(Request $request, Response $response, array $args): Response
    {
        $categoryId = $this->intParam($args, 'category_id', 0);
        if ($categoryId <= 0) {
            return $this->error($response, 'InvalidInput', 400, 'A valid category ID is required.');
        }

        $existing = $this->category_repository->get($categoryId);
        if ($existing === null) {
            return $this->error($response, 'CategoryNotFound', 404, 'The category does not exist.');
        }

        $result = $this->buildValidatedCategory($response, $this->parsedBody($request), $existing);
        if ($result instanceof Response) {
            return $result;
        }
        $category = $result;

        if ($this->category_repository->save($category) === 0) {
            return $this->error($response, 'SaveFailed', 500, 'Could not save the category.');
        }

        $this->invalidateCache(CacheGroup::Tags);
        $this->logger->info('Category edited', ['category_id' => $categoryId, 'name' => $category->category_name]);
        return $this->success($response, $category);
    }

    /**
     * DELETE /tag-categories/{category_id} — Delete a category that has no tags.
     */
    #[OAS\Operation\Delete(
        path: '/tag-categories/{category_id}',
        summary: 'Delete a tag category',
        tags: ['Tag Categories'],
        security: [new OAS\Security\Requirement(scheme: 'bearerAuth')],
        parameters: [new OAS\Parameter(name: 'category_id', in: 'path', required: true, schema: new OAS\Schema(type: 'integer'))],
        responses: [
            new OAS\Response(response: 204, description: 'Deleted'),
            new OAS\Response(response: 400, description: 'CategoryInUse', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
            new OAS\Response(response: 404, description: 'CategoryNotFound', content: new OAS\MediaType\Json(ref: '#/components/schemas/ErrorResponse')),
        ]
    )]
    public function deleteCategory(Request $request, Response $response, array $args): Response
    {
        $categoryId = $this->intParam($args, 'category_id', 0);

        if ($categoryId <= 0) {
            return $this->error($response, 'InvalidInput', 400, 'A valid category ID is required.');
        }

        $category = $this->category_repository->get($categoryId);
        if ($category === null) {
            return $this->error($response, 'CategoryNotFound', 404, 'The category does not exist.');
        }

        $tagCount = $this->category_repository->countTags($categoryId);
        if ($tagCount > 0) {
            return $this->error(
                $response,
                'CategoryInUse',
                400,
                "Cannot delete \"{$category->category_name}\" because {$tagCount} tag(s) belong to it. Reassign them first."
            );
        }

        $deleted = $this->category_repository->delete($category);
        if (!$deleted) {
            return $this->error($response, 'DeleteFailed', 500, 'Could not delete the category.');
        }

        $this->invalidateCache(CacheGroup::Tags);
        $this->logger->info('Category deleted', ['category_id' => $categoryId]);
        return $this->noContent($response);
    }

    /**
     * Validates category input from the request and applies it to a TagCategory.
     *
     * On success returns the populated category (fresh, or $existing mutated in
     * place). On any validation failure returns the error Response to send back.
     * When $existing is given, its ID is excluded from the name/shortcode
     * conflict check.
     *
     * @param array<string, mixed> $params
     */
    private function buildValidatedCategory(Response $response, array $params, ?TagCategory $existing = null): TagCategory|Response
    {
        $name = trim($params['category_name'] ?? '');
        $color = trim($params['color'] ?? 'white');
        $description = trim($params['description'] ?? '');
        $sortOrder = (int) ($params['sort_order'] ?? 0);

        if ($name === '') {
            return $this->error($response, 'InvalidInput', 400, 'Category name is required.');
        }
        if (mb_strlen($name) > self::MAX_CATEGORY_NAME_LENGTH) {
            return $this->error($response, 'NameTooLong', 400, 'Category name must be ' . self::MAX_CATEGORY_NAME_LENGTH . ' characters or fewer.');
        }
        if (!in_array($color, self::VALID_COLORS, true)) {
            return $this->error($response, 'InvalidColor', 400, 'The selected color is not valid.');
        }

        $excludeId = $existing instanceof TagCategory ? $existing->category_id : 0;
        $conflicts = $this->category_repository->checkConflicts($name, $excludeId);
        if (in_array('name', $conflicts, true)) {
            return $this->error($response, 'NameTaken', 400, "A category named \"{$name}\" already exists.");
        }

        $category = $existing ?? new TagCategory();
        return $category->setCategoryName($name)
                 ->setColor($color)
                 ->setDescription($description)
                 ->setSortOrder($sortOrder);
    }
}
