<?php

declare(strict_types=1);

use Crab\Engine\Network\Connection;
use Crab\Engine\Runtime;

require dirname(__DIR__, 3) . '/vendor/autoload.php';

$certificate = $argv[1];
$options = ['local_cert' => $certificate];
$runtime = new Runtime();
$protocol = static function (Connection $connection) use ($options): void {
    $connection->tryWrite("220 localhost TLS transport fixture\r\n");
    $buffer = '';
    $connection->onData(static function (Connection $connection, string $bytes) use (&$buffer, $options): void {
        $buffer .= $bytes;
        if (strlen($buffer) > 8192) {
            $connection->close();
            return;
        }
        while (($end = strpos($buffer, "\r\n")) !== false) {
            $line = substr($buffer, 0, $end);
            $buffer = substr($buffer, $end + 2);
            if (str_starts_with(strtoupper($line), 'EHLO ')) {
                $connection->tryWrite(
                    $connection->isEncrypted() ? "250 localhost\r\n" : "250-localhost\r\n250 STARTTLS\r\n",
                );
            } elseif (strtoupper($line) === 'STARTTLS' && !$connection->isEncrypted()) {
                $connection->tryWrite("220 Begin TLS\r\n");
                $connection->startTls(static function (): void {}, $options, unread: $buffer);
                $buffer = '';
                return;
            } elseif (strtoupper($line) === 'QUIT') {
                $connection->tryWrite("221 Bye\r\n");
                $connection->end();
                return;
            } else {
                $connection->tryWrite("250 OK\r\n");
            }
        }
    });
};
$runtime
    ->group('tls-fixture')
    ->listen('tcp://127.0.0.1:4650', $protocol, tls: $options)
    ->listen('tcp://127.0.0.1:5870', $protocol)
    ->onStart(static function (): void {
        fwrite(STDOUT, "ready\n");
    });
$runtime->run(singleProcess: true);
