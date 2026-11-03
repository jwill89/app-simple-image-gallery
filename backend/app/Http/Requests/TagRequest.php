<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Tag;
use App\Models\TagCategory;
use Illuminate\Validation\Rule;

/**
 * A tag, as created or replaced. The name is normalized first, so the checks
 * and the stored tag see the same name; without a category the tag goes in
 * category 1 (General), even one being replaced.
 */
final class TagRequest extends ApiRequest
{
    private const int MAX_NAME_LENGTH = 100;

    private const int DEFAULT_CATEGORY = 1;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'tag_name' => [
                'required',
                'string',
                'max:' . self::MAX_NAME_LENGTH,
                Rule::unique(Tag::class, 'tag_name')->ignore($this->route('tag_id'), 'tag_id'),
            ],
            'category_id' => ['sometimes', 'integer', Rule::exists(TagCategory::class, 'category_id')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'tag_name' => 'Tag name cannot be empty.',
            'tag_name.max' => 'Tag name must be :max characters or fewer.',
            'tag_name.unique' => 'A tag named ":input" already exists.',
            'category_id' => 'The selected tag category is not valid.',
        ];
    }

    /** @return array{tag_name: string, category_id: int} */
    public function tag(): array
    {
        return [
            'tag_name' => $this->string('tag_name')->toString(),
            'category_id' => $this->integer('category_id', self::DEFAULT_CATEGORY),
        ];
    }

    protected function errorCodes(): array
    {
        return [
            'tag_name.max' => ['TagNameTooLong', 400],
            'tag_name.unique' => ['TagAlreadyExists', 400],
            'tag_name' => ['InvalidTagName', 400],
            'category_id' => ['InvalidCategoryID', 400],
        ];
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('tag_name');
        if (is_string($name)) {
            $this->merge(['tag_name' => Tag::normalizeName($name)]);
        }
    }
}
