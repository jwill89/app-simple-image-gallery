<?php

declare(strict_types=1);

namespace App\Http\Requests;

/**
 * Starting to add a passkey: the account's password, so a browser left signed
 * in can't give someone else a way in that outlasts signing out.
 */
final class PasskeyOptionsRequest extends ApiRequest
{
    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return ['password' => ['required', 'string']];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return ['password' => 'Your password is required.'];
    }

    protected function errorCodes(): array
    {
        return ['password' => ['MissingCredentials', 400]];
    }
}
