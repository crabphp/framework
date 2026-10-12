<?php

declare(strict_types=1);

/**
 * In-process broadcast fan-out over real socket pairs, and matched HTTP
 * and echo paths. No server is started. Run with CLI opcache and JIT on.
 */
$loader = require dirname(__DIR__) . '/vendor/autoload.php';

use Crab\App;
use Crab\Broadcast\Broadcaster;
use Crab\Broadcast\Channels;
use Crab\Broadcast\LocalCarrier;
use Crab\Contracts\WebSocket\Socket as SocketContract;
use Crab\Engine\EventLoop;
use Crab\Engine\EventLoop\RevoltEventLoop;
use Crab\Engine\Network\Connection;
use Crab\Engine\WebSocket\WebSocketSession;
use Crab\Http\Request;
use Crab\Testing\Api;
use Crab\WebSocket\Settings;
use Crab\WebSocket\Socket;

$options = getopt('', ['mode:', 'source:', 'batch:', 'iterations:', 'trials:']);
$mode = $options['mode'] ?? 'fanout';
$batch = (int) ($options['batch'] ?? 128);
$trials = (int) ($options['trials'] ?? 3);
$iterations = (int) ($options['iterations'] ?? ($mode === 'echo' ? 5000 : 100000));
if (isset($options['source'])) {
    $source = realpath($options['source']);
    if ($source === false) { throw new InvalidArgumentException('Give --source the framework src directory.'); }
    $loader->setPsr4('Crab\\', [$source]);
    $classes = [];
    foreach ($loader->getClassMap() as $class => $file) {
        if (str_starts_with($class, 'Crab\\') && !str_starts_with($class, 'Crab\\Tests\\')) {
            $classes[$class] = $source . '/' . str_replace('\\', '/', substr($class, 5)) . '.php';
        }
    }
    $loader->addClassMap($classes);
}
if (!in_array($mode, ['fanout', 'http', 'echo', 'empty'], true) || min($batch, $trials, $iterations) < 1) {
    throw new InvalidArgumentException('Choose --mode=fanout|http|echo|empty and positive batch, trials and iterations.');
}

/**
 * A deferred probe measures the longest wait while fan-out is pending.
 * It stops the loop once write acceptance has completed, before peer reads.
 */
final class FanOutProbe
{
    public int $longest = 0;
    public int $finished = 0;
    private int $last;

    public function __construct(private readonly EventLoop $loop, private readonly Channels $channels)
    {
        $this->last = hrtime(true);
    }

    public function sample(): void
    {
        $now = hrtime(true);
        $this->longest = max($this->longest, $now - $this->last);
        $this->last = $now;
        if ($this->channels->stats()['pending_bytes'] === 0) {
            $this->finished = $now;
            $this->loop->stop();
        } else {
            $this->loop->defer($this->sample(...));
        }
    }
}

$userMicroseconds = static function (): float {
    $usage = getrusage();
    return $usage['ru_utime.tv_sec'] * 1e6 + $usage['ru_utime.tv_usec'];
};
$result = ['mode' => $mode, 'php' => PHP_VERSION, 'jit' => opcache_get_status(false)['jit']['on'] ?? false];
if ($mode === 'fanout') {
    $result['batch'] = $batch;
    $result['backend'] = extension_loaded('ev') ? 'ev' : 'select';
    if (!extension_loaded('ev')) {
        throw new RuntimeException('Load ev to measure 10,000 real socket pairs; select cannot watch their descriptors.');
    }
    foreach ([1000, 10000] as $count) {
        $loop = EventLoop::install(RevoltEventLoop::create('ev'));
        $carrier = new LocalCarrier();
        $channels = new Channels($carrier, batch: $batch);
        $broadcast = new Broadcaster($carrier);
        $settings = new Settings(ping: 0);
        $request = new Request('GET', '/live');
        $sockets = $transports = $peers = [];
        for ($index = 0; $index < $count; ++$index) {
            $pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
            stream_set_blocking($pair[1], false);
            $transport = new Connection($loop, $pair[0]);
            $socket = new Socket($loop, $request, static function (): void {}, $settings);
            $session = new WebSocketSession($transport, static function (): void {}, $settings->engine);
            $socket->attach($session, $transport);
            $sockets[] = $socket;
            $transports[] = $transport;
            $peers[] = $pair[1];
        }
        $memory = memory_get_usage();
        foreach ($sockets as $socket) { $channels->join($socket, 'orders'); }
        $oneChannel = memory_get_usage() - $memory;
        $memory = memory_get_usage();
        foreach ($sockets as $socket) { $channels->join($socket, 'announcements'); }
        $secondChannel = memory_get_usage() - $memory;
        $rows = [];
        for ($trial = -2; $trial < $trials; ++$trial) {
            $probe = new FanOutProbe($loop, $channels);
            $started = hrtime(true);
            $broadcast->to('orders')->send(str_repeat('x', 32));
            $handedOff = hrtime(true);
            $loop->defer($probe->sample(...));
            $loop->run();
            $errors = 0;
            foreach ($peers as $peer) {
                $wire = fread($peer, 34);
                if ($wire !== "\x81\x20" . str_repeat('x', 32)) { ++$errors; }
            }
            if ($trial < 0) { continue; }
            $rows[] = [
                'fanout_ms' => ($probe->finished - $started) / 1e6,
                'sender_us' => ($handedOff - $started) / 1e3,
                'longest_probe_wait_ms' => $probe->longest / 1e6,
                'errors' => $errors,
            ];
        }
        $result['connections'][] = ['count' => $count, 'first_channel_bytes_per_connection' => $oneChannel / $count,
            'second_channel_bytes_per_connection' => $secondChannel / $count, 'trials' => $rows];
        $channels->close();
        foreach ($transports as $transport) { $transport->close(); }
        foreach ($peers as $peer) { fclose($peer); }
        $loop->defer($loop->stop(...));
        $loop->run();
        if ($loop->getIdentifiers() !== []) { throw new RuntimeException('The benchmark leaked a watcher.'); }
        unset($socket, $session, $transport, $sockets, $transports, $peers);
        gc_collect_cycles();
    }
} else {
    $loop = EventLoop::install(RevoltEventLoop::create());
    if ($mode === 'empty') {
        $app = App::configure()->withMiddleware(only: [])->create();
        $app->container->get(\Crab\Broadcast\Lifecycle::class)->serving = true;
        $broadcast = $app->container->get(\Crab\Contracts\Broadcast\Broadcaster::class)->to('nobody');
        $work = static function (int $count) use ($broadcast): void {
            for ($index = 0; $index < $count; ++$index) { $broadcast->send('text'); }
        };
    } else {
        $app = App::configure()->withMiddleware(only: [])->create();
        if ($mode === 'http') {
            $app->router->get('/hello/{id}', static fn (int $id): array => ['id' => $id]);
            $work = static function (int $count) use ($app): void {
                for ($index = 0; $index < $count; ++$index) {
                    $response = $app->handle(new Request('GET', '/hello/' . ($index % 997)));
                    if ($response->getStatusCode() !== 200) { throw new RuntimeException('HTTP benchmark failed.'); }
                }
            };
        } else {
            $app->router->websocket('/live', static function (SocketContract $socket): void {
                foreach ($socket as $message) { $socket->send($message); }
            });
            $api = new Api($app);
            $client = $api->websocket('/live');
            $work = static function (int $count) use ($client): void {
                for ($index = 0; $index < $count; ++$index) {
                    $text = 'hello ' . $index;
                    $client->send($text);
                    if ($client->receive() !== $text) { throw new RuntimeException('Echo benchmark failed.'); }
                }
            };
        }
    }
    $work(min(20000, $iterations));
    $rows = [];
    for ($trial = 0; $trial < $trials; ++$trial) {
        $started = $userMicroseconds();
        $work($iterations);
        $rows[] = ($userMicroseconds() - $started) / $iterations;
    }
    $result['iterations'] = $iterations;
    $result['user_us_per_operation'] = $rows;
    if (isset($api)) { $api->closeSockets(); }
}
echo json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
