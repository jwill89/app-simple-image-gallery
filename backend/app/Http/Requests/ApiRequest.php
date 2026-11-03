<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Exceptions\ApiException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\Attributes\StopOnFirstFailure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * A form request that fails the way the API contract does: the first failed
 * rule, in the order rules() lists them, becomes `{ error, message }` with that
 * endpoint's code and status (usually 400), never Laravel's 422 with
 * `{ message, errors }`. The message is the rule's, so messages() sets the
 * wording the contract uses.
 *
 * Authorization is the routes' business (auth:sanctum), so every request is
 * authorized here.
 */
#[StopOnFirstFailure]
abstract class ApiRequest extends FormRequest
{
    /**
     * The contract's code and status for a failed rule, keyed "field.rule"
     * (snake_case rule name) or just "field" for any rule on it. A failure with
     * no entry is 400 InvalidInput.
     *
     * @return array<string, array{0: string, 1: int}>
     */
    abstract protected function errorCodes(): array;

    public function authorize(): bool
    {
        return true;
    }

    protected function failedValidation(Validator $validator): never
    {
        $codes = $this->errorCodes();

        foreach ($validator->failed() as $field => $rules) {
            $rule = Str::snake((string) array_key_first($rules));
            [$code, $status] = $codes["{$field}.{$rule}"] ?? $codes[$field] ?? ['InvalidInput', 400];

            throw new ApiException($code, $status, $validator->errors()->first($field));
        }

        throw new ApiException('InvalidInput', 400, $validator->errors()->first());
    }
}
