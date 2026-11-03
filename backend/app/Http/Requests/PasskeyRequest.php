<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Exceptions\ApiException;
use Laravel\Passkeys\Support\WebAuthn;
use Throwable;
use Webauthn\PublicKeyCredential;

/**
 * A passkey's answer, to add it (with an optional name) or to sign in with it
 * (with remember). `credential` is the browser's PublicKeyCredential as JSON,
 * as its toJSON() writes it.
 */
final class PasskeyRequest extends ApiRequest
{
    /** The fields of the browser's answer, each refused with the same code and message. */
    private const array CREDENTIAL_FIELDS = ['credential', 'credential.id', 'credential.rawId', 'credential.type', 'credential.response'];

    protected function prepareForValidation(): void
    {
        $this->trimFields('name');
    }

    /** @return array<string, list<mixed>> */
    public function rules(): array
    {
        return [
            'credential' => ['required', 'array'],
            'credential.id' => ['required', 'string'],
            'credential.rawId' => ['required', 'string'],
            'credential.type' => ['required', 'string', 'in:public-key'],
            'credential.response' => ['required', 'array'],
            'name' => ['sometimes', 'nullable', 'string', 'max:64'],
            'remember' => ['sometimes', 'boolean'],
        ];
    }

    /** @return array<string, string> */
    public function messages(): array
    {
        return [
            ...array_fill_keys(self::CREDENTIAL_FIELDS, 'The passkey\'s answer is missing or incomplete.'),
            'name' => 'A passkey\'s name is at most 64 characters.',
            'remember' => 'remember is true or false.',
        ];
    }

    protected function errorCodes(): array
    {
        return [
            ...array_fill_keys(self::CREDENTIAL_FIELDS, ['InvalidPasskey', 400]),
            'name' => ['InvalidPasskeyName', 400],
            'remember' => ['InvalidInput', 400],
        ];
    }

    /** The answer, read by the WebAuthn library. */
    public function credential(): PublicKeyCredential
    {
        try {
            $credential = WebAuthn::fromJson(json_encode($this->input('credential'), JSON_THROW_ON_ERROR), PublicKeyCredential::class);
        } catch (Throwable) {
            $credential = null;
        }

        return $credential instanceof PublicKeyCredential
            ? $credential
            : throw new ApiException('InvalidPasskey', 400, 'The passkey\'s answer could not be read. Please try again.');
    }
}
