<?php

declare(strict_types=1);

namespace Crab\Database\Mysql;

/**
 * A transport failure, distinguished from an exception in user value conversion.
 * Only transport failures before execution can justify reconnecting and retrying.
 *
 * @internal
 */
final class StreamFailure extends \RuntimeException {}
