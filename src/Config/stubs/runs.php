<?php

declare(strict_types=1);

// Every setting of this section, at its default. Delete a line to go back to the default.
return [
    // The runs' table and the table of their steps, as `runs:install` names them.
    'table' => 'runs',
    'steps' => 'run_steps',

    // The name of the database they are in; the default one when null.
    'database' => null,

    // The queue their work is pushed to; the queue's own, "queue.queue", when null.
    'queue' => null,

    // How many times a run is tried before it is failed, and the seconds before the second try, the third, and so on.
    'tries' => 3,
    'backoff' => [10, 60],

    // Seconds a worker's hold on a run lasts unless renewed; it is renewed every third of this while the run runs.
    'lease' => 90,
];
