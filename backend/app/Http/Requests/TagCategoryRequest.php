<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\TagCategory;
use Illuminate\Validation\Rule;

/**
 * A tag category, as created or edited: a new one needs a name and a colour,
 * and an edit changes only the fields it sends.
 */
final class TagCategoryRequest extends ApiRequest
{
    private const int MAX_NAME_LENGTH = 50;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $sometimes = $this->editing() !== null ? ['sometimes'] : [];

        return [
            'categoryName' => [
                ...$sometimes,
                'required',
                'string',
                'max:' . self::MAX_NAME_LENGTH,
                // Without regard to case (the column is COLLATE NOCASE), and not
                // against the category being edited.
                Rule::unique(TagCategory::class, 'category_name')->ignore($this->editing()?->category_id, 'category_id'),
            ],
            'color' => [...$sometimes, 'required', Rule::in(TagCategory::COLORS)],
            'description' => ['nullable', 'string'],
            'sortOrder' => ['nullable', 'integer'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'categoryName.required' => 'Category name is required.',
            'categoryName.max' => 'Category name must be :max characters or fewer.',
            'categoryName.unique' => 'A category named ":input" already exists.',
            'color' => 'The selected color is not valid.',
        ];
    }

    /**
     * The category's fields: for a new one every field, with the defaults for
     * any left out; for an edit only those sent.
     *
     * @return array{category_name?: string, color?: string, description?: string, sort_order?: int}
     */
    public function category(): array
    {
        $fields = [
            'category_name' => $this->string('categoryName')->toString(),
            'color' => $this->string('color')->toString(),
            'description' => $this->string('description')->toString(),
            'sort_order' => $this->integer('sortOrder'),
        ];
        $sent = ['category_name' => 'categoryName', 'color' => 'color', 'description' => 'description', 'sort_order' => 'sortOrder'];

        return $this->editing() === null ? $fields : array_filter($fields, fn(string $column): bool => $this->has($sent[$column]), ARRAY_FILTER_USE_KEY);
    }

    protected function errorCodes(): array
    {
        return [
            'categoryName.max' => ['NameTooLong', 422],
            'categoryName.unique' => ['NameTaken', 409],
            'color' => ['InvalidColor', 422],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimFields('categoryName', 'color', 'description');
    }

    /** The category being edited, or null when one is being created. */
    private function editing(): ?TagCategory
    {
        $category = $this->route('categoryId');

        return $category instanceof TagCategory ? $category : null;
    }
}
