<?php

declare(strict_types=1);

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

final class BenchUser extends Model
{
    protected $table = 'users';
    public $timestamps = false;

    protected function casts(): array
    {
        return ['id' => 'integer', 'age' => 'integer'];
    }
}
