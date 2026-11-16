<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Pagination\Cursor;

/**
 * GET /media's query: which media (`tags`, or `untagged`), and which part of
 * them, by `limit` and either `offset` (a numbered page) or `cursor` (the items
 * after a page another response ended with).
 */
final class MediaListRequest extends ApiRequest
{
    public const int DEFAULT_LIMIT = 40;

    public const int MAX_LIMIT = 100;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:' . self::MAX_LIMIT],
            'offset' => ['sometimes', 'integer', 'min:0', 'prohibits:cursor'],
            'cursor' => ['sometimes', 'string', static function (string $attribute, mixed $value, \Closure $fail): void {
                if (!is_string($value) || ($value !== '' && Cursor::fromEncoded($value) === null)) {
                    $fail('The cursor is not one this listing gave out.');
                }
            }],
            'tags' => ['sometimes', 'string', 'max:2000', 'prohibits:untagged'],
            'untagged' => ['sometimes', 'in:true,false,1,0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'limit' => 'limit is a whole number from 1 to ' . self::MAX_LIMIT . '.',
            'offset' => 'offset is a whole number, 0 or more.',
            'offset.prohibits' => 'Page by offset or by cursor, not both.',
            'tags' => 'tags is a comma-separated list of tag names, at most 2000 characters.',
            'tags.prohibits' => 'Ask for tagged or untagged media, not both.',
            'untagged' => 'untagged is true or false.',
        ];
    }

    protected function errorCodes(): array
    {
        return ['cursor' => ['InvalidCursor', 422]];
    }

    public function limit(): int
    {
        return $this->integer('limit', self::DEFAULT_LIMIT);
    }

    public function offset(): int
    {
        return $this->integer('offset');
    }

    public function cursor(): ?Cursor
    {
        return $this->filled('cursor') ? Cursor::fromEncoded($this->string('cursor')->toString()) : null;
    }

    public function untagged(): bool
    {
        return $this->boolean('untagged');
    }

    /** The tag search as written ("cat,-dog"), or null for no search. */
    public function tags(): ?string
    {
        return $this->filled('tags') ? $this->string('tags')->toString() : null;
    }
}
