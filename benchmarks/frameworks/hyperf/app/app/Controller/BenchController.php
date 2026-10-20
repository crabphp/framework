<?php

declare(strict_types=1);

namespace App\Controller;

use Hyperf\DbConnection\Db;
use Hyperf\HttpServer\Contract\RequestInterface;
use Hyperf\HttpServer\Contract\ResponseInterface as ResponseFactory;
use Hyperf\Validation\Contract\ValidatorFactoryInterface;
use Psr\Http\Message\ResponseInterface;

final class BenchController
{
    public function __construct(
        private ResponseFactory $response,
        private ValidatorFactoryInterface $validation,
    ) {}

    public function hello(): ResponseInterface
    {
        return $this->response->json(['message' => 'Hello, world']);
    }

    public function user(RequestInterface $request, string $id): ResponseInterface
    {
        $row = Db::table('users')
            ->where('id', (int) $id)
            ->first();
        return $row
            ? $this->response->json((array) $row)
            : $this->response->json(['error' => 'Not found'])->withStatus(404);
    }

    public function create(RequestInterface $request): ResponseInterface
    {
        $data = $request->all();
        $validator = $this->validation->make($data, [
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
            return $this->response->json(['errors' => $validator->errors()])->withStatus(422);
        }
        return $this->response->json(array_intersect_key($data, array_flip(['name', 'email', 'age'])))->withStatus(201);
    }

    public function metadata(): ResponseInterface
    {
        return $this->response->json(
            (require '/bench/metadata.php')
            + [
                'workers' => 2,
                'swoole' => phpversion('swoole'),
                'scan_cacheable' => \Hyperf\Context\ApplicationContext::getContainer()->get(
                    \Hyperf\Contract\ConfigInterface::class,
                )->get('scan_cacheable'),
                'pool_per_worker' => 10,
            ],
        );
    }
}
