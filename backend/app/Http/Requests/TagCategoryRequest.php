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
            'category_name' => [
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
            'sort_order' => ['nullable', 'integer'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'category_name.required' => 'Category name is required.',
            'category_name.max' => 'Category name must be :max characters or fewer.',
            'category_name.unique' => 'A category named ":input" already exists.',
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
            'category_name' => $this->string('category_name')->toString(),
            'color' => $this->string('color')->toString(),
            'description' => $this->string('description')->toString(),
            'sort_order' => $this->integer('sort_order'),
        ];

        return $this->editing() === null ? $fields : array_intersect_key($fields, $this->all());
    }

    protected function errorCodes(): array
    {
        return [
            'category_name.max' => ['NameTooLong', 400],
            'category_name.unique' => ['NameTaken', 400],
            'color' => ['InvalidColor', 400],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimFields('category_name', 'color', 'description');
    }

    /** The category being edited, or null when one is being created. */
    private function editing(): ?TagCategory
    {
        $category = $this->route('category_id');

        return $category instanceof TagCategory ? $category : null;
    }
}
