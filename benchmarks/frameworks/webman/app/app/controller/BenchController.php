<?php

declare(strict_types=1);

namespace app\controller;

use support\Db;
use support\Request;
use support\Response;
use support\validation\Validator;

final class BenchController
{
    public function hello(): Response
    {
        return json(['message' => 'Hello, world']);
    }

    public function user(Request $request, string $id): Response
    {
        $row = Db::table('users')
            ->where('id', (int) $id)
            ->first();
        return $row ? json((array) $row) : json(['error' => 'Not found'])->withStatus(404);
    }

    public function create(Request $request): Response
    {
        $data = $request->post();
        $validator = Validator::make($data, [
            'name' => ['present', 'string', 'min:1', 'max:100'],
            'email' => ['required', 'string', 'email:rfc'],
            'age' => [
                'required',
                'integer',
                'min:18',
                'max:120',
                static function ($attribute, $value, $fail): void {
                    if (!is_int($value)) {
                        $fail('Age must be a JSON integer.');
                    }
                },
            ],
        ]);
        $validator->after(static function ($validator) use ($data): void {
            $name = $data['name'] ?? null;
            if (is_string($name) && trim($name) === '' && (mb_strlen($name) < 1 || mb_strlen($name) > 100)) {
                $validator->errors()->add('name', 'Name length must be 1–100.');
            }
        });
        if ($validator->fails()) {
            return json(['errors' => $validator->errors()])->withStatus(422);
        }
        return json(array_intersect_key($data, array_flip(['name', 'email', 'age'])))->withStatus(201);
    }

    public function metadata(): Response
    {
        return json(array_replace(require '/bench/metadata.php', [
            'debug' => (bool) config('app.debug'),
            'workers' => config('process.webman.count'),
            'event' => phpversion('event'),
            'persistent' => true,
            'pool_per_worker' => 10,
        ]));
    }
}
