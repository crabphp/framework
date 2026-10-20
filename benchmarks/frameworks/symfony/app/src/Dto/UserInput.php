<?php

declare(strict_types=1);

namespace App\Dto;

use Symfony\Component\Validator\Constraints as Assert;

final readonly class UserInput
{
    public function __construct(
        #[Assert\Length(min: 1, max: 100)]
        public string $name,
        #[Assert\NotBlank, Assert\Email]
        public string $email,
        #[Assert\Range(min: 18, max: 120)]
        public int $age,
    ) {}
}
