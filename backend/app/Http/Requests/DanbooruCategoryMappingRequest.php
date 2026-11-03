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
            'danbooru_category_id' => ['required', 'integer', 'min:0'],
            'danbooru_category_name' => ['required', 'string'],
            'gallery_category_id' => ['required', 'integer', 'min:1', Rule::exists(TagCategory::class, 'category_id')],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'danbooru_category_id' => 'Danbooru category ID must be a non-negative integer.',
            'danbooru_category_name' => 'Danbooru category name is required.',
            'gallery_category_id' => 'A valid gallery category must be selected.',
            'gallery_category_id.exists' => 'The selected gallery category does not exist.',
        ];
    }

    /** @return array{danbooru_category_id: int, danbooru_category_name: string, gallery_category_id: int} */
    public function mapping(): array
    {
        return [
            'danbooru_category_id' => $this->integer('danbooru_category_id'),
            'danbooru_category_name' => $this->string('danbooru_category_name')->toString(),
            'gallery_category_id' => $this->integer('gallery_category_id'),
        ];
    }

    protected function errorCodes(): array
    {
        return ['gallery_category_id.exists' => ['CategoryNotFound', 404]];
    }

    protected function prepareForValidation(): void
    {
        $this->trimFields('danbooru_category_name');
    }
}
