<?php

declare(strict_types=1);

namespace Crab\Engine;

use Crab\Engine\EventLoop\SelectLoop;
use Crab\Engine\Server;

class Crab
{
    private static array $servers = [];

    public static function addServer(Server $server)
    {
        self::$servers[] = $server;
    }

    public static function run(): void
    {
        $loop = new SelectLoop();

        foreach (self::$servers as $server) {
            $server->attach($loop);
        }

        $loop->run();
    }
}
