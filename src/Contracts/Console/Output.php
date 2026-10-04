<?php

declare(strict_types=1);

namespace Crab\Contracts\Console;

/**
 * Where a command writes its results and its problems.
 */
interface Output
{
    public function write(string $text): void;

    public function line(string $text = ''): void;

    public function error(string $text): void;

    /**
     * Rows as columns padded to their widest cell.
     *
     * @param list<list<string>> $rows
     */
    public function table(array $rows, string $indent = ''): void;
}
