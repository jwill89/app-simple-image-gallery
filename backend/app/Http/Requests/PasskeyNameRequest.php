<?php

declare(strict_types=1);

namespace App\Http\Requests;

/** A passkey's new name, as its list on the Account page shows it. */
final class PasskeyNameRequest extends ApiRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:64', 'regex:/\S/']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'name' => 'A passkey needs a name.',
            'name.max' => 'A passkey\'s name is at most 64 characters.',
        ];
    }

    protected function errorCodes(): array
    {
        return ['name' => ['InvalidPasskeyName', 400]];
    }
}
