<?php

declare(strict_types=1);

namespace Crab\Container;

use Psr\Container\NotFoundExceptionInterface;

final class NotFoundException extends ContainerException implements NotFoundExceptionInterface {}
