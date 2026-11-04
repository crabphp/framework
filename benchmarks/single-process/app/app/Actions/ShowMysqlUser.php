<?php

declare(strict_types=1);

namespace App\Actions;

use Crab\Contracts\Database\Database;
use Crab\Http\HttpException;

final readonly class ShowMysqlUser
{
    public function __construct(
        private Database $db,
    ) {}

    public function handle(int $id): array
    {
        return $this->db->connection('mysql')->table('users')->where('id', $id)->first() ?? throw new HttpException(404, 'Not found.');
    }
}
