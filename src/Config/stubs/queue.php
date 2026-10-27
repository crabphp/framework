<?php

declare(strict_types=1);

// Every setting of this section, at its default. Delete a line to go back to the default.
return [
    // Where jobs are kept: database, memory for tests, or redis with the crabphp/redis package.
    'store' => env('QUEUE_STORE', 'database'),

    // The table, and the name of the database it is in; the default one when null.
    'table' => 'jobs',
    'database' => null,

    // The queue jobs are pushed to and taken from when none is named.
    'queue' => 'default',

    // How many times a job is started before it is given up on, and seconds to wait before each try after the first; the last repeats.
    'tries' => 3,
    'backoff' => [10, 60],

    // Seconds a taken job may go unrenewed, its worker dead or blocked, before another worker is given it.
    'retry_after' => 90,

    // Seconds one try of a job may run, unless the job says with #[Timeout]; null for no limit.
    'timeout' => null,

    // Worker processes `queue:work` starts, and jobs one worker runs at a time.
    'workers' => env('QUEUE_WORKERS', 1),
    'concurrency' => 1,

    // Seconds a worker waits before looking again when no job was due, and is given to finish its job when
    // told to stop, or a job past its timeout to stop before its process is ended.
    'sleep' => 1.0,
    'grace' => 30.0,

    // Named sets of workers, each with its own queues and limits; see the framework's docs/queues.md.
    'supervisors' => [],
];
