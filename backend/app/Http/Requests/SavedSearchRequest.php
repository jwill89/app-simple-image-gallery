<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\SavedSearch;
use Illuminate\Validation\Rule;

/**
 * A saved search: a name (up to 60 characters, unique among the account's
 * searches) and the query as the gallery's URL writes it. A PATCH may send
 * either.
 */
final class SavedSearchRequest extends ApiRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $search = $this->route('search_id');
        $required = $this->isMethod('POST') ? 'required' : 'sometimes';

        return [
            'name' => [
                $required,
                'string',
                'max:60',
                Rule::unique(SavedSearch::class, 'name')
                    ->where('user_id', $this->user()?->getAuthIdentifier())
                    ->ignore($search instanceof SavedSearch ? $search->search_id : null, 'search_id'),
            ],
            'query' => [$required, 'string', 'max:500'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name' => 'A search name is 1 to 60 characters.',
            'name.unique' => 'You already have a search by that name.',
            'query' => 'A search is 1 to 500 characters of tags.',
        ];
    }

    protected function errorCodes(): array
    {
        return [
            'name' => ['InvalidSearchName', 400],
            'name.unique' => ['SearchExists', 409],
            'query' => ['InvalidQuery', 400],
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->trimFields('name', 'query');
    }
}
