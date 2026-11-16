<?php

declare(strict_types=1);

namespace App\Http\Requests;

/** A paged table's query: `limit` (1 to 100, 40 by default) items from `offset` on. */
final class PageRequest extends ApiRequest
{
    public const int DEFAULT_LIMIT = 40;

    public const int MAX_LIMIT = 100;

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'limit' => ['sometimes', 'integer', 'min:1', 'max:' . self::MAX_LIMIT],
            'offset' => ['sometimes', 'integer', 'min:0'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'limit' => 'limit is a whole number from 1 to ' . self::MAX_LIMIT . '.',
            'offset' => 'offset is a whole number, 0 or more.',
        ];
    }

    protected function errorCodes(): array
    {
        return [];
    }

    public function limit(): int
    {
        return $this->integer('limit', self::DEFAULT_LIMIT);
    }

    public function offset(): int
    {
        return $this->integer('offset');
    }
}
