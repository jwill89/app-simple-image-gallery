<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Tag;
use App\Models\TagCategory;
use Illuminate\Validation\Rule;

/**
 * A tag, as created or edited. The name is normalized first, so the checks and
 * the stored tag see the same name. A new tag without a category goes in
 * category 1 (General); an edit changes only the fields it sends.
 */
final class TagRequest extends ApiRequest
{
    private const int MAX_NAME_LENGTH = 100;

    private const int DEFAULT_CATEGORY = 1;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'tagName' => [
                // An edit changes only the fields it sends.
                ...($this->editing() !== null ? ['sometimes'] : []),
                'required',
                'string',
                'max:' . self::MAX_NAME_LENGTH,
                Rule::unique(Tag::class, 'tag_name')->ignore($this->editing()?->tag_id, 'tag_id'),
            ],
            'categoryId' => ['sometimes', 'integer', Rule::exists(TagCategory::class, 'category_id')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'tagName' => 'Tag name cannot be empty.',
            'tagName.max' => 'Tag name must be :max characters or fewer.',
            'tagName.unique' => 'A tag named ":input" already exists.',
            'categoryId' => 'The selected tag category is not valid.',
        ];
    }

    /**
     * The tag's fields: for a new tag every one, the category defaulting to 1;
     * for an edit only those sent.
     *
     * @return array{tag_name?: string, category_id?: int}
     */
    public function tag(): array
    {
        $fields = [];
        if ($this->editing() === null || $this->has('tagName')) {
            $fields['tag_name'] = $this->string('tagName')->toString();
        }
        if ($this->editing() === null || $this->has('categoryId')) {
            $fields['category_id'] = $this->integer('categoryId', self::DEFAULT_CATEGORY);
        }

        return $fields;
    }

    /** The tag being edited, or null when one is being created. */
    private function editing(): ?Tag
    {
        $tag = $this->route('tagId');

        return $tag instanceof Tag ? $tag : null;
    }

    protected function errorCodes(): array
    {
        return [
            'tagName.max' => ['TagNameTooLong', 422],
            'tagName.unique' => ['TagAlreadyExists', 409],
            'tagName' => ['InvalidTagName', 422],
            'categoryId' => ['InvalidCategoryID', 422],
        ];
    }

    protected function prepareForValidation(): void
    {
        $name = $this->input('tagName');
        if (is_string($name)) {
            $this->merge(['tagName' => Tag::normalizeName($name)]);
        }
    }
}
