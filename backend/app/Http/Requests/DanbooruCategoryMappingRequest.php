<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\TagCategory;
use Illuminate\Validation\Rule;

/** Which gallery category a Danbooru tag category imports into. */
final class DanbooruCategoryMappingRequest extends ApiRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            // Danbooru numbers its categories from 0 (General).
            'danbooruCategoryId' => ['required', 'integer', 'min:0'],
            'danbooruCategoryName' => ['required', 'string', 'max:50'],
            'galleryCategoryId' => ['required', 'integer', 'min:1', Rule::exists(TagCategory::class, 'category_id')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'danbooruCategoryId' => 'Danbooru category ID must be a non-negative integer.',
            'danbooruCategoryName' => 'Danbooru category name is required.',
            'danbooruCategoryName.max' => 'Danbooru category name is at most 50 characters.',
            'galleryCategoryId' => 'A valid gallery category must be selected.',
            'galleryCategoryId.exists' => 'The selected gallery category does not exist.',
        ];
    }

    /** @return array{danbooru_category_id: int, danbooru_category_name: string, gallery_category_id: int} */
    public function mapping(): array
    {
        return [
            'danbooru_category_id' => $this->integer('danbooruCategoryId'),
            'danbooru_category_name' => $this->string('danbooruCategoryName')->toString(),
            'gallery_category_id' => $this->integer('galleryCategoryId'),
        ];
    }

    protected function errorCodes(): array
    {
        return ['galleryCategoryId.exists' => ['CategoryNotFound', 422]];
    }

    protected function prepareForValidation(): void
    {
        $this->trimFields('danbooruCategoryName');
    }
}
