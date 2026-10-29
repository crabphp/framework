<?php

declare(strict_types=1);

namespace Crab\Contracts\Actors;

/**
 * An ask() that got no reply: none came in time, or the actor ended or
 * failed before it replied.
 */
final class NoReply extends \RuntimeException {}
