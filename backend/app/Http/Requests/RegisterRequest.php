<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Validation\Rule;

/** A new account: a username (3 to 32 letters, digits, ".", "_" or "-") and a password. */
final class RegisterRequest extends ApiRequest
{
    public const string USERNAME_PATTERN = '/^[A-Za-z0-9._-]{3,32}$/';

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'username' => ['required', 'string', 'regex:' . self::USERNAME_PATTERN, Rule::unique(User::class, 'username')],
            'password' => ['required', 'string', ...self::passwordRules()],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * What every password must be, at sign-up and when changed.
     *
     * @return list<string>
     */
    public static function passwordRules(): array
    {
        return ['min:8', 'max:200'];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            'username' => 'A username is 3 to 32 letters, digits, dots, dashes or underscores.',
            'username.unique' => 'That username is taken.',
            'password' => 'A password is at least 8 characters.',
            'password.max' => 'A password is at most 200 characters.',
            'remember' => '"remember" is true or false.',
        ];
    }

    protected function errorCodes(): array
    {
        return [
            'username' => ['InvalidUsername', 400],
            'username.unique' => ['UsernameTaken', 409],
            'password' => ['WeakPassword', 400],
        ];
    }
}
