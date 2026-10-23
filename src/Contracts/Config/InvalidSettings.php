<?php

declare(strict_types=1);

namespace Crab\Contracts\Config;

/**
 * Thrown when settings do not fit what they take. Its message names every
 * one of them and is written for whoever runs the application, so it is
 * shown as it is, without a stack trace.
 */
interface InvalidSettings extends \Throwable
{
    /**
     * One sentence for each setting that does not fit, as Config::problems() gives them.
     *
     * @return list<string>
     */
    public function problems(): array;
}
