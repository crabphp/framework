<?php

declare(strict_types=1);

namespace Crab\Contracts\Log;

use Psr\Log\LoggerInterface;

/**
 * Writes records of what the application does: info(), warning(),
 * error() and the rest. It is PSR-3's logger under Crab's own name, so
 * that application code asks for a Crab type and Crab has a place to add
 * to it; a logger from any PSR-3 library fits wherever only those methods
 * are needed.
 */
interface Logger extends LoggerInterface {}
