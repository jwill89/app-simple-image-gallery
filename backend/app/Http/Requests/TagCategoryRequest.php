<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\TagCategory;
use Illuminate\Validation\Rule;

/**
 * A tag category, as created or replaced: a fresh category or every field of
 * an existing one, so a field left out goes back to its default.
 */
final class TagCategoryRequest extends ApiRequest
{
    private const int MAX_NAME_LENGTH = 50;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'category_name' => [
                'required',
                'string',
                'max:' . self::MAX_NAME_LENGTH,
                // Without regard to case (the column is COLLATE NOCASE), and not
                // against the category being replaced.
                Rule::unique(TagCategory::class, 'category_name')->ignore($this->route('category_id'), 'category_id'),
            ],
            'color' => ['required', Rule::in(TagCategory::COLORS)],
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
     * The category's fields, with the defaults for any left out.
     *
     * @return array{category_name: string, color: string, description: string, sort_order: int}
     */
    public function category(): array
    {
        return [
            'category_name' => $this->string('category_name')->toString(),
            'color' => $this->string('color')->toString(),
            'description' => $this->string('description')->toString(),
            'sort_order' => $this->integer('sort_order'),
        ];
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
}
