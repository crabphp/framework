<?php

declare(strict_types=1);

// Every setting of this section, at its default. Delete a line to go back to the default.
return [
    // A file, or a stream such as php://stderr. A relative path such as storage/logs/app.log is inside the application.
    'path' => env('LOG_PATH', 'php://stderr'),

    // The least severe level written: debug, info, notice, warning, error, critical, alert or emergency.
    'level' => env('LOG_LEVEL', 'debug'),
];
