<?php

declare(strict_types=1);

namespace Crab\WebSocket;

/**
 * The application-facing name of the immutable contract value.
 * Both names denote exactly the same value, including in socket signatures.
 */
class_alias(\Crab\Contracts\WebSocket\Binary::class, __NAMESPACE__ . '\\Binary');
