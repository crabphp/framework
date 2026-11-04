<?php

declare(strict_types=1);

namespace Crab\Runs;

/**
 * Thrown inside a run whose worker lost it: its lease ran out and another
 * worker claimed it. Nothing it would keep from then on is kept.
 *
 * @internal
 */
final class Overtaken extends \RuntimeException {}
