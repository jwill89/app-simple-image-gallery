<?php

declare(strict_types=1);

namespace App\Http\Requests;

/** A login: username and password, and whether to keep the login for 30 days. */
final class LoginRequest extends ApiRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string'],
            'password' => ['required', 'string'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'username' => 'A username and password are required.',
            'password' => 'A username and password are required.',
            'remember' => '"remember" is true or false.',
        ];
    }

    protected function errorCodes(): array
    {
        return ['username' => ['MissingCredentials', 400], 'password' => ['MissingCredentials', 400]];
    }
}
