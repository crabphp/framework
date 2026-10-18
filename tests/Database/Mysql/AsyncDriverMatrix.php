<?php

declare(strict_types=1);

namespace Crab\Tests\Database\Mysql;

use Crab\Database\Mysql\Connection;
use Crab\Engine\Process\Worker;
use Crab\Testing\WorkerHarness;

/**
 * Runs the existing MySQL test body unchanged inside a real worker.
 * Only its connection factory changes; assertions and SQL keep the MySQL branch.
 */
trait AsyncDriverMatrix
{
    private ?Worker $matrixWorker = null;
    private ?\WeakMap $matrixConnections = null;

    protected function invokeTestMethod(string $methodName, array $testArguments): mixed
    {
        if (!in_array('mysql-async', $testArguments, true)) {
            return parent::invokeTestMethod($methodName, $testArguments);
        }
        $testArguments = array_map(static fn(mixed $value): mixed => $value === 'mysql-async'
            ? 'mysql'
            : $value, $testArguments);
        return WorkerHarness::run([], function (Worker $worker) use ($methodName, $testArguments): mixed {
            $this->matrixWorker = $worker;
            $this->matrixConnections = new \WeakMap();
            try {
                return parent::invokeTestMethod($methodName, $testArguments);
            } finally {
                foreach ($this->matrixConnections as $connection => $_) {
                    $connection->close();
                }
                $this->matrixWorker = null;
                $this->matrixConnections = null;
            }
        });
    }

    private function mysqlFactory(string $driver, ?\Closure $opened = null): ?\Closure
    {
        if ($driver !== 'mysql' || $this->matrixWorker === null) {
            return null;
        }
        return function (\Closure $owner) use ($opened): Connection {
            $opened?->__invoke();
            $connection = new Connection(
                $owner,
                (string) getenv('CRAB_TEST_MYSQL_DSN'),
                getenv('CRAB_TEST_MYSQL_USER') ?: 'root',
                getenv('CRAB_TEST_MYSQL_PASSWORD') ?: '',
                worker: $this->matrixWorker,
            );
            $this->matrixConnections[$connection] = true;
            return $connection;
        };
    }
}
