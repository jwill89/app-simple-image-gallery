<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\DanbooruTagMapping;
use Illuminate\Validation\Rule;

/**
 * A Danbooru tag name and the gallery tag name it imports as: both for a new
 * mapping, either for an edit, which changes only the fields it sends.
 */
final class DanbooruTagMappingRequest extends ApiRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $sometimes = $this->editing() !== null ? ['sometimes'] : [];

        return [
            // One mapping per Danbooru tag, other than the one being edited.
            'danbooruTag' => [...$sometimes, 'required', 'string', 'max:200', Rule::unique(DanbooruTagMapping::class, 'danbooru_tag')->ignore($this->editing()?->id)],
            'galleryTag' => [...$sometimes, 'required', 'string', 'max:200'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'danbooruTag' => 'Danbooru tag name is required.',
            'danbooruTag.max' => 'A Danbooru tag name is at most 200 characters.',
            'danbooruTag.unique' => 'A mapping for ":input" already exists.',
            'galleryTag' => 'Gallery tag name is required.',
            'galleryTag.max' => 'A gallery tag name is at most 200 characters.',
        ];
    }

    /**
     * The mapping's columns: for a new one both, for an edit those sent.
     *
     * @return array{danbooru_tag?: string, gallery_tag?: string}
     */
    public function mapping(): array
    {
        $fields = [];
        if ($this->editing() === null || $this->has('danbooruTag')) {
            $fields['danbooru_tag'] = $this->string('danbooruTag')->toString();
        }
        if ($this->editing() === null || $this->has('galleryTag')) {
            $fields['gallery_tag'] = $this->string('galleryTag')->toString();
        }

        return $fields;
    }

    protected function errorCodes(): array
    {
        return ['danbooruTag.unique' => ['DuplicateMapping', 409]];
    }

    protected function prepareForValidation(): void
    {
        $this->trimFields('danbooruTag', 'galleryTag');
    }

    /** The mapping being edited, or null when one is being created. */
    private function editing(): ?DanbooruTagMapping
    {
        $mapping = $this->route('mappingId');

        return $mapping instanceof DanbooruTagMapping ? $mapping : null;
    }
}
