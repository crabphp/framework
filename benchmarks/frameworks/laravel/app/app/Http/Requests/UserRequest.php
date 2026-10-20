<?php

declare(strict_types=1);

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class UserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'name' => ['present', 'string', 'min:1', 'max:100'],
            'email' => ['required', 'string', 'email:rfc'],
            'age' => [
                'required',
                'integer',
                'min:18',
                'max:120',
                static function (string $attribute, mixed $value, \Closure $fail): void {
                    if (!is_int($value)) {
                        $fail('The age must be a JSON integer.');
                    }
                },
            ],
        ];
    }

    public function after(): array
    {
        // Laravel skips non-implicit rules on empty/whitespace strings. Keep the literal JSON length contract.
        return [static function (\Illuminate\Validation\Validator $validator): void {
            $name = $validator->getData()['name'] ?? null;
            if (is_string($name) && trim($name) === '' && (mb_strlen($name) < 1 || mb_strlen($name) > 100)) {
                $validator->errors()->add('name', 'The name must contain 1 to 100 characters.');
            }
        }];
    }
}
