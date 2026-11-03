<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\FavoriteList;
use Illuminate\Validation\Rule;

/** A list's name: up to 60 characters, unique among the account's lists. */
final class FavoriteListRequest extends ApiRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        $list = $this->route('list_id');

        return [
            'name' => [
                'required',
                'string',
                'max:60',
                Rule::unique(FavoriteList::class, 'name')
                    ->where('user_id', $this->user()?->getAuthIdentifier())
                    ->ignore($list instanceof FavoriteList ? $list->list_id : null, 'list_id'),
            ],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name' => 'A list name is 1 to 60 characters.',
            'name.unique' => 'You already have a list by that name.',
        ];
    }

    protected function errorCodes(): array
    {
        return ['name' => ['InvalidListName', 400], 'name.unique' => ['ListExists', 409]];
    }

    protected function prepareForValidation(): void
    {
        $this->trimFields('name');
    }
}
