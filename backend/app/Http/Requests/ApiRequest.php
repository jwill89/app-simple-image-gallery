<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Exceptions\ApiException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * A form request that fails the way the API contract does: the first failed
 * rule, in the order rules() lists them, gives `{ error, message }` that
 * endpoint's code and status (usually 400), never Laravel's 422. Every field is
 * checked, and `errors` lists each broken field's messages, so a form can mark
 * them all. The messages are the rules', so messages() sets the wording.
 *
 * Authorization is the routes' business (auth, admin), so every request is
 * authorized here.
 */
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

    /**
     * Trim these fields where they are strings, for a prepareForValidation()
     * whose fields are checked and stored trimmed. Requests aren't trimmed as
     * a whole (bootstrap/app.php removes TrimStrings).
     */
    protected function trimFields(string ...$fields): void
    {
        $this->merge(array_map(trim(...), array_filter($this->only($fields), is_string(...))));
    }

    protected function failedValidation(Validator $validator): never
    {
        // Every broken field's messages, so a form can mark each one.
        $extra = ['errors' => $validator->errors()->toArray()];
        $codes = $this->errorCodes();

        foreach ($validator->failed() as $field => $rules) {
            $rule = Str::snake((string) array_key_first($rules));
            [$code, $status] = $codes["{$field}.{$rule}"] ?? $codes[$field] ?? ['InvalidInput', 400];

            throw new ApiException($code, $status, $validator->errors()->first($field), [], $extra);
        }

        throw new ApiException('InvalidInput', 400, $validator->errors()->first(), [], $extra);
    }
}
