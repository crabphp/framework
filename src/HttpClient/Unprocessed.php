<?php

declare(strict_types=1);

namespace Crab\HttpClient;

/**
 * A request the server says it did not act on, so that making it again,
 * on another connection, cannot do it twice.
 *
 * @internal
 */
final class Unprocessed extends \RuntimeException {}
