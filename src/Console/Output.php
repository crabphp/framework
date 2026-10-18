<?php

declare(strict_types=1);

namespace Crab\Console;

use Crab\Contracts\Console\Output as OutputContract;

/**
 * Writes results to standard output and problems to standard error.
 */
final readonly class Output implements OutputContract
{
    /**
     * @param resource $out
     * @param resource $error
     */
    public function __construct(
        private mixed $out = STDOUT,
        private mixed $error = STDERR,
    ) {}

    public function write(string $text): void
    {
        fwrite($this->out, $text);
    }

    public function line(string $text = ''): void
    {
        fwrite($this->out, $text . "\n");
    }

    public function error(string $text): void
    {
        fwrite($this->error, $text . "\n");
    }

    public function table(array $rows, string $indent = ''): void
    {
        $widths = [];
        foreach ($rows as $row) {
            foreach ($row as $column => $cell) {
                $widths[$column] = max($widths[$column] ?? 0, strlen($cell));
            }
        }
        foreach ($rows as $row) {
            $line = '';
            foreach ($row as $column => $cell) {
                $line .= str_pad($cell, $widths[$column] + 2);
            }
            $this->line($indent . rtrim($line));
        }
    }
}
