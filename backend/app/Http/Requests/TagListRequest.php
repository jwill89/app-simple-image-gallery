<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\Tag;

/**
 * GET /tags's query: which tags (`ids`, `names`, `search` or `filter`, less
 * `exclude`), in what order (`search`'s ranking, or `sort` and `direction`),
 * which part (`limit`, `offset`), and whether each carries its counts
 * (`include=counts`).
 */
final class TagListRequest extends ApiRequest
{
    public const int MAX_LIMIT = 100;

    /** The most ids or names one lookup may name. */
    public const int MAX_LOOKUP = 100;

    public const array SORTS = ['tagName' => 'tags.tag_name', 'categoryName' => 'tag_categories.category_name', 'mediaCount' => 'media_count'];

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $idList = 'regex:/^\d+(,\d+)*$/';

        return [
            'ids' => ['sometimes', 'string', $idList, $this->countAtMost(self::MAX_LOOKUP)],
            'names' => ['sometimes', 'string', $this->countAtMost(self::MAX_LOOKUP)],
            'search' => ['sometimes', 'string', 'max:200', 'prohibits:filter,sort'],
            'filter' => ['sometimes', 'string', 'max:200'],
            'exclude' => ['sometimes', 'string', $idList],
            'sort' => ['sometimes', 'in:' . implode(',', array_keys(self::SORTS))],
            'direction' => ['sometimes', 'in:asc,desc'],
            'limit' => ['sometimes', 'integer', 'min:1', 'max:' . self::MAX_LIMIT],
            'offset' => ['sometimes', 'integer', 'min:0'],
            'include' => ['sometimes', 'in:counts'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'ids' => 'ids is a comma-separated list of up to ' . self::MAX_LOOKUP . ' tag IDs.',
            'names' => 'names is a comma-separated list of up to ' . self::MAX_LOOKUP . ' tag names.',
            'search' => 'search is at most 200 characters.',
            'search.prohibits' => 'search ranks its own matches: leave out filter and sort.',
            'filter' => 'filter is at most 200 characters.',
            'exclude' => 'exclude is a comma-separated list of tag IDs.',
            'sort' => 'sort is tagName, categoryName or mediaCount.',
            'direction' => 'direction is asc or desc.',
            'limit' => 'limit is a whole number from 1 to ' . self::MAX_LIMIT . '.',
            'offset' => 'offset is a whole number, 0 or more.',
            'include' => 'include is "counts" or left out.',
        ];
    }

    protected function errorCodes(): array
    {
        return [];
    }

    /** @return list<int>|null */
    public function ids(): ?array
    {
        return $this->has('ids') ? self::numbers($this->string('ids')->toString()) : null;
    }

    /** @return list<string>|null */
    public function names(): ?array
    {
        return $this->has('names')
            ? array_values(array_filter(array_map(Tag::normalizeName(...), explode(',', $this->string('names')->toString())), static fn(string $name): bool => $name !== ''))
            : null;
    }

    /** @return list<int> */
    public function exclude(): array
    {
        return self::numbers($this->string('exclude')->toString());
    }

    public function search(): ?string
    {
        return $this->has('search') ? Tag::normalizeName($this->string('search')->toString()) : null;
    }

    public function filter(): string
    {
        return mb_strtolower(trim($this->string('filter')->toString()), 'UTF-8');
    }

    /** The column to order by. */
    public function sort(): string
    {
        return self::SORTS[$this->string('sort', 'tagName')->toString()] ?? 'tags.tag_name';
    }

    /** @return 'asc'|'desc' */
    public function direction(): string
    {
        return $this->query('direction') === 'desc' ? 'desc' : 'asc';
    }

    public function limit(): int
    {
        return $this->integer('limit', self::MAX_LIMIT);
    }

    public function offset(): int
    {
        return $this->integer('offset');
    }

    public function withCounts(): bool
    {
        return $this->query('include') === 'counts';
    }

    /** A rule refusing a comma-separated list of more than $max entries. */
    private function countAtMost(int $max): \Closure
    {
        return static function (string $attribute, mixed $value, \Closure $fail) use ($max): void {
            if (is_string($value) && count(explode(',', $value)) > $max) {
                $fail("{$attribute} names at most {$max}.");
            }
        };
    }

    /** @return list<int> */
    private static function numbers(string $list): array
    {
        return array_values(array_unique(array_filter(array_map(intval(...), explode(',', $list)), static fn(int $id): bool => $id > 0)));
    }
}
