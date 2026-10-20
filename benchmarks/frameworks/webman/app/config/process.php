<?php

return [
    'webman' => [
        'handler' => app\process\Http::class,
        'listen' => 'http://0.0.0.0:8080',
        'count' => 2,
        'eventLoop' => Workerman\Events\Event::class,
        'constructor' => [
            'requestClass' => support\Request::class,
            'logger' => support\Log::channel('default'),
            'appPath' => app_path(),
            'publicPath' => public_path(),
        ],
    ],
];
