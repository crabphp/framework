<?php

declare(strict_types=1);

namespace Crab\Contracts\Queue;

use Crab\Contracts\Database\Connection;

/**
 * A store that keeps its jobs in a database, and so can push one as part
 * of a transaction on that database: the job is there when the
 * transaction commits and is not when it is rolled back.
 */
interface TransactionalStore extends Store
{
    /**
     * This store, pushing through the transaction's connection; null when
     * the connection is of another database than the jobs are kept in.
     */
    public function through(Connection $transaction): ?Store;
}
