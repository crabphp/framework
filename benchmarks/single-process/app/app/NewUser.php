<?php

declare(strict_types=1);

namespace App;

use Crab\Validation\Email;
use Crab\Validation\Length;
use Crab\Validation\Range;

final readonly class NewUser
{
    public function __construct(
        #[Length(min: 1, max: 100)]
        public string $name,
        #[Email]
        public string $email,
        #[Range(min: 18, max: 120)]
        public int $age,
    ) {}
}
