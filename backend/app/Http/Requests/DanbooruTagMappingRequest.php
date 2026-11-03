<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\DanbooruTagMapping;
use Illuminate\Validation\Rule;

/** A Danbooru tag name and the gallery tag name it imports as. */
final class DanbooruTagMappingRequest extends ApiRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            // One mapping per Danbooru tag, other than the one being replaced.
            'danbooru_tag' => ['required', 'string', Rule::unique(DanbooruTagMapping::class, 'danbooru_tag')->ignore($this->route('id'))],
            'gallery_tag' => ['required', 'string'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'danbooru_tag' => 'Danbooru tag name is required.',
            'danbooru_tag.unique' => 'A mapping for ":input" already exists.',
            'gallery_tag' => 'Gallery tag name is required.',
        ];
    }

    /** @return array{danbooru_tag: string, gallery_tag: string} */
    public function mapping(): array
    {
        return [
            'danbooru_tag' => $this->string('danbooru_tag')->toString(),
            'gallery_tag' => $this->string('gallery_tag')->toString(),
        ];
    }

    protected function errorCodes(): array
    {
        return ['danbooru_tag.unique' => ['DuplicateMapping', 400]];
    }

    protected function prepareForValidation(): void
    {
        $this->trimFields('danbooru_tag', 'gallery_tag');
    }
}
