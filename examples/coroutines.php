<?php

declare(strict_types=1);

use Crab\Engine\Async\Async;
use Crab\Engine\Async\Cancellation;
use Crab\Engine\Process\Worker;
use Crab\Engine\Runtime;

$runtime = new Runtime();
$runtime->group()->onStart(static function (Worker $worker): void {
    $first = $worker->spawn(static function (Cancellation $cancel) use ($worker): string {
        Async::sleep($worker->loop, 0.05, $cancel);
        return 'first task';
    });
    $second = $worker->spawn(static function (Cancellation $cancel) use ($worker): string {
        Async::sleep($worker->loop, 0.02, $cancel);
        return 'second task';
    });
    $worker->spawn(static function (Cancellation $cancel) use ($first, $second, $worker): void {
        fwrite(STDOUT, $first->await($cancel).', '.$second->await($cancel)."\n");
        $worker->stop();
    });
});

return $runtime;
