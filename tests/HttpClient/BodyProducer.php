<?php

declare(strict_types=1);

namespace Crab\Tests\HttpClient;

use Crab\Http\Stream;

/**
 * A pipe body produced only after the exchange's actual deadline. The
 * first read releases the child, so slow startup cannot replace the
 * intended ordering with a guessed delay.
 */
final class BodyProducer
{
    /**
     * The ordinary PSR-7 stream over the child's output pipe.
     */
    public readonly Stream $body;
    /**
     * The child and its control pipe belong to this fixture alone.
     *
     * @var resource
     */
    private mixed $process;
    /**
     * @var resource
     */
    private mixed $control;
    private bool $released = false;

    public function __construct()
    {
        $program = <<<'PHP'
            $line = fgets(STDIN);
            if ($line === false) {
                exit;
            }
            $deadline = (float) $line;
            while (hrtime(true) / 1_000_000_000 < $deadline) {
                usleep(1000);
            }
            echo 'late';
            PHP;
        $process = proc_open(
            [PHP_BINARY, '-r', $program],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['file', '/dev/null', 'w']],
            $pipes,
        );
        if (!is_resource($process)) {
            throw new \RuntimeException('Cannot start the owned request-body producer.');
        }
        $this->process = $process;
        $this->control = $pipes[0];
        $this->body = new Stream($pipes[1]);
    }

    /**
     * The child learns the deadline only when the exchange reads its body.
     */
    public function readAfter(float $deadline, int $length): string
    {
        if (!$this->released) {
            fwrite($this->control, sprintf("%.9f\n", $deadline));
            fflush($this->control);
            $this->released = true;
        }
        return $this->body->read($length);
    }

    /**
     * Closing the control pipe also releases a child whose body was never read.
     */
    public function close(): void
    {
        fclose($this->control);
        $this->body->close();
        proc_close($this->process);
    }
}
