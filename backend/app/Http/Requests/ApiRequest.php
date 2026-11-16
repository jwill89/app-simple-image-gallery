<?php

declare(strict_types=1);

namespace App\Http\Requests;

use App\Exceptions\ApiException;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Str;

/**
 * A form request that fails as problem details: the first failed rule, in the
 * order rules() lists them, gives the endpoint's code and status (422 unless
 * errorCodes() says otherwise), and its message is the `detail`. Every field is
 * checked, and `errors` lists each broken field once, as `{ message, location }`
 * with the location in huma's form (`body.tagName`, `query.limit`), so a form
 * can mark them all. The messages are the rules', so messages() sets the
 * wording.
 *
 * Authorization is the routes' business (auth, admin), so every request is
 * authorized here.
 */
abstract class ApiRequest extends FormRequest
{
    /**
     * The code and status for a failed rule, keyed "field.rule" (snake_case
     * rule name) or just "field" for any rule on it. A failure with no entry is
     * 422 InvalidInput.
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

    /** Where the checked fields come from: a read's query string, a write's body. */
    protected function location(): string
    {
        return $this->isMethod('GET') ? 'query' : 'body';
    }

    protected function failedValidation(Validator $validator): never
    {
        $errors = [];
        foreach ($validator->errors()->toArray() as $field => $messages) {
            $errors[] = ['message' => (string) ($messages[0] ?? ''), 'location' => $this->location() . '.' . $field];
        }
        $codes = $this->errorCodes();

        foreach ($validator->failed() as $field => $rules) {
            $rule = Str::snake((string) array_key_first($rules));
            [$code, $status] = $codes["{$field}.{$rule}"] ?? $codes[$field] ?? ['InvalidInput', 422];

            throw new ApiException($code, $status, $validator->errors()->first($field), [], $errors);
        }

        throw new ApiException('InvalidInput', 422, $validator->errors()->first(), [], $errors);
    }
}
