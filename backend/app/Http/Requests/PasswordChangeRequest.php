<?php

declare(strict_types=1);

namespace App\Http\Requests;

/** An account's new password, with its current one to prove it's the owner. */
final class PasswordChangeRequest extends ApiRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'currentPassword' => ['required', 'string'],
            'password' => ['required', 'string', ...RegisterRequest::passwordRules()],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'currentPassword' => 'Your current password is required.',
            'password' => 'A password is at least 8 characters.',
            'password.max' => 'A password is at most 200 characters.',
        ];
    }

    protected function errorCodes(): array
    {
        return ['currentPassword' => ['MissingCredentials', 422], 'password' => ['WeakPassword', 422]];
    }
}
