<?php

declare(strict_types=1);

namespace Crab\Tests\Database\Mysql;

use Crab\Database\DatabaseException;
use Crab\Database\Mysql\Connection;
use PHPUnit\Framework\TestCase;

/**
 * Scripted command responses exercise protocol refusal and fallback behavior
 * that a local MySQL version may not produce for ordinary statements.
 */
final class ProtocolTest extends TestCase
{
    public function testUnsupportedPreparesFallBackOnlyWhenThereAreNoBindings(): void
    {
        foreach ([[], ['secret']] as $bindings) {
            ScriptedServer::run(static function (ScriptedServer $server) use ($bindings): void {
                self::login($server);
                $server->sequence = 0;
                self::assertSame("\x16", $server->receive()[0]);
                $server->send("\xff" . pack('v', 1295) . '#HY000Unsupported prepare');
                if ($bindings === []) {
                    $server->sequence = 0;
                    self::assertSame("\x03LOCK TABLES example WRITE", $server->receive());
                    $server->ok();
                } else {
                    self::assertTrue($server->eof());
                }
            }, static function (string $dsn) use ($bindings): void {
                $connection = new Connection($dsn);
                try {
                    if ($bindings === []) {
                        self::assertSame(0, $connection->execute('LOCK TABLES example WRITE'));
                    } else {
                        try {
                            $connection->execute('select ?', $bindings);
                            self::fail('Bound values were sent through a text fallback.');
                        } catch (DatabaseException $error) {
                            self::assertSame(1295, $error->code());
                            self::assertStringNotContainsString('secret', $error->getMessage());
                        }
                    }
                } finally {
                    $connection->close();
                }
            });
        }
    }

    public function testLocalInfileRequestsAreRefusedAndTheirConnectionIsClosed(): void
    {
        ScriptedServer::run(static function (ScriptedServer $server): void {
            self::login($server);
            $server->prepare();
            $server->sequence = 0;
            $server->receive();
            $server->send("\xfbsensitive-file");
            self::assertTrue($server->eof());
        }, static function (string $dsn): void {
            $connection = new Connection($dsn);
            try {
                $connection->execute('LOAD DATA LOCAL INFILE ...');
                self::fail('A file request was accepted.');
            } catch (DatabaseException $error) {
                self::assertStringContainsString('refuses LOCAL INFILE', $error->getMessage());
                self::assertStringNotContainsString('sensitive-file', $error->getMessage());
            } finally {
                $connection->close();
            }
        });
    }

    public function testALargeResultYieldsToTheLoopWhileItIsRead(): void
    {
        ScriptedServer::run(static function (ScriptedServer $server): void {
            self::login($server);
            $server->prepare(columns: 1);
            $server->sequence = 0;
            $server->receive();
            $server->send("\x01");
            $server->send(ScriptedServer::column('data', 252));
            $server->send("\xfe\0\0\x02\0");
            $server->send("\0\0" . \Crab\Database\Mysql\Codec::string(str_repeat('x', 18_000_000)));
            $server->send("\xfe\0\0\x02\0");
        }, static function (string $dsn, \Crab\Engine\Process\Worker $worker): void {
            $connection = new Connection($dsn);
            $ticks = 0;
            $timer = $worker->loop->repeat(0.001, static function () use (&$ticks): void {
                ++$ticks;
            });
            try {
                self::assertSame(18_000_000, strlen($connection->first('select large_blob')['data']));
                self::assertGreaterThan(1, $ticks);
            } finally {
                $worker->loop->cancel($timer);
                $connection->close();
            }
        });
    }

    public function testALostPrepareCanReconnectOnceBeforeExecutionIsSent(): void
    {
        foreach ([false, true] as $twice) {
            $executions = 0;
            ScriptedServer::run(
                static function (ScriptedServer $server, int $index) use ($twice, &$executions): void {
                    self::login($server);
                    if ($index === 0 || $twice) {
                        $server->sequence = 0;
                        self::assertSame("\x16", $server->receive()[0]);
                        $server->close();
                        return;
                    }
                    $server->prepare();
                    $server->sequence = 0;
                    self::assertSame("\x17", $server->receive()[0]);
                    ++$executions;
                    $server->ok();
                },
                static function (string $dsn) use ($twice): void {
                    $connection = new Connection($dsn);
                    try {
                        if ($twice) {
                            try {
                                $connection->execute('insert into example values (1)');
                                self::fail('Two consecutive losses were retried again.');
                            } catch (DatabaseException) {
                            }
                        } else {
                            self::assertSame(0, $connection->execute('insert into example values (1)'));
                        }
                    } finally {
                        $connection->close();
                    }
                },
                connections: 2,
            );
            self::assertSame($twice ? 0 : 1, $executions);
        }
    }

    public function testEvictingAStatementClosesItsHandleOnTheServer(): void
    {
        ScriptedServer::run(static function (ScriptedServer $server): void {
            self::login($server);
            for ($id = 1; $id <= 257; ++$id) {
                $server->prepare($id);
                if ($id === 257) {
                    $server->sequence = 0;
                    self::assertSame("\x19" . pack('V', 1), $server->receive());
                }
                $server->sequence = 0;
                $execute = $server->receive();
                self::assertSame("\x17" . pack('V', $id), substr($execute, 0, 5));
                $server->ok();
            }
        }, static function (string $dsn): void {
            $connection = new Connection($dsn);
            try {
                for ($i = 0; $i < 257; ++$i) {
                    self::assertSame(0, $connection->execute("select {$i}"));
                }
            } finally {
                $connection->close();
            }
        });
    }

    public function testACachedHandleRebuildsItsRowLayoutWhenServerMetadataChanges(): void
    {
        ScriptedServer::run(
            static function (ScriptedServer $server): void {
                self::login($server);
                $server->prepare(columns: 1);
                foreach ([false, true] as $changed) {
                    $server->sequence = 0;
                    self::assertSame("\x17" . pack('V', 1), substr($server->receive(), 0, 5));
                    $server->send("\x01");
                    $server->send(ScriptedServer::column($changed ? 'renamed' : 'n', $changed ? 253 : 3));
                    $server->send("\xfe\0\0\x02\0");
                    $server->send("\0\0" . ($changed ? \Crab\Database\Mysql\Codec::string('changed') : pack('V', 42)));
                    $server->send("\0\x04"); // The changed definition must also retain NULL bitmap handling.
                    $server->send("\xfe\0\0\x02\0");
                }
            },
            static function (string $dsn): void {
                $connection = new Connection($dsn);
                try {
                    self::assertSame([['n' => 42], ['n' => null]], $connection->select('select example'));
                    self::assertSame(
                        [['renamed' => 'changed'], ['renamed' => null]],
                        $connection->select('select example'),
                    );
                } finally {
                    $connection->close();
                }
            },
        );
    }

    private static function login(ScriptedServer $server): void
    {
        $server->greeting('mysql_native_password');
        $server->receive();
        $server->ok();
    }
}
