<?php

declare(strict_types=1);

namespace Crab\Engine\Process;

use Crab\Engine\Network\Listener;
use Crab\Engine\Runtime;

/**
 * A server process as its Keeper started it: listening on the socket the
 * keeper holds, and reporting to the keeper when it accepts connections.
 *
 *     $generation = Generation::inherit();
 *     $runtime = new Runtime();
 *     $runtime->group('web')->listen($address, $handler, socket: $generation->socket);
 *     $generation->attach($runtime);
 *     $runtime->run();
 */
final class Generation
{
    /**
     * @param resource $socket The listening socket passed on by the keeper.
     * @param resource|null $report Where the keeper is told how the start went.
     */
    private function __construct(public readonly mixed $socket, private mixed $report)
    {
    }

    /**
     * Takes up what the keeper passed to this process. Fails in a process
     * that no keeper started.
     */
    public static function inherit(): self
    {
        $socket = Listener::inherited(Keeper::SOCKET);
        $report = @fopen('php://fd/' . Keeper::REPORT, 'wb');
        if ($report === false) {
            throw new \RuntimeException('This process was not started by a keeper: it has nowhere to report to.');
        }
        return new self($socket, $report);
    }

    /**
     * Makes the runtime tell the keeper when it is ready, and stop when
     * the keeper has gone: a server no keeper knows of could be neither
     * reloaded nor stopped.
     */
    public function attach(Runtime $runtime): void
    {
        $runtime->onSignal(SIGQUIT, $runtime->force(...));
        $runtime->onReady(function (): void {
            $this->tell(true, (string) getmypid());
        });
        $keeper = posix_getppid();
        $runtime->onSignal(SIGALRM, static function () use ($runtime, $keeper): void {
            if (posix_getppid() !== $keeper) {
                echo date('c') . ' The keeper, pid ' . $keeper . ", has gone; stopping.\n";
                $runtime->stop();
                return;
            }
            pcntl_alarm(2);
        });
        pcntl_alarm(2);
    }

    /**
     * Tells the keeper this process could not start. After it has said it
     * was ready, nothing is told.
     */
    public function fail(string $message): void
    {
        $this->tell(false, $message);
    }

    private function tell(bool $started, string $message): void
    {
        if (is_resource($this->report)) {
            @fwrite($this->report, json_encode([$started, $message], JSON_INVALID_UTF8_SUBSTITUTE) . "\n");
            fclose($this->report);
        }
        $this->report = null;
    }
}
