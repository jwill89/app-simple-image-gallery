<?php

declare(strict_types=1);

namespace App\Http\Requests;

/** What an admin may change on an account: whether it's an admin, and its password. */
final class UserUpdateRequest extends ApiRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'is_admin' => ['sometimes', 'boolean'],
            'password' => ['sometimes', 'string', ...RegisterRequest::passwordRules()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'is_admin' => '"is_admin" is true or false.',
            'password' => 'A password is at least 8 characters.',
            'password.max' => 'A password is at most 200 characters.',
        ];
    }

    protected function errorCodes(): array
    {
        return ['password' => ['WeakPassword', 400]];
    }
}
