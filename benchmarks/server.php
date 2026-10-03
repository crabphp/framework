<?php

declare(strict_types=1);

$loader = require dirname(__DIR__) . '/vendor/autoload.php';

use Crab\Engine\Http\Request;
use Crab\Engine\Http\Response;
use Crab\Engine\Network\WriteResult;
use Crab\Engine\Runtime;
use Crab\Engine\Server\HttpServer;
use Crab\Engine\Server\WebSocketServer;
use Crab\Engine\WebSocket\MessageType;
use Crab\Engine\WebSocket\WebSocketSession;
use Revolt\EventLoop\Driver\EvDriver;
use Revolt\EventLoop\Driver\EventDriver;
use Revolt\EventLoop\Driver\StreamSelectDriver;
use Revolt\EventLoop\Driver\UvDriver;

$options = getopt('', ['protocol:', 'host:', 'port:', 'workers:', 'source:', 'http-response:', 'driver:', 'max-connections:', 'header-timeout:', 'metadata']);
$protocol = $options['protocol'] ?? 'http';
$host = $options['host'] ?? '127.0.0.1';
$port = filter_var($options['port'] ?? 8080, FILTER_VALIDATE_INT);
$workers = filter_var($options['workers'] ?? 1, FILTER_VALIDATE_INT);
$responseMode = $options['http-response'] ?? 'reuse';
// Native backends are optional; each needs its PHP extension loaded.
$drivers = ['select' => StreamSelectDriver::class, 'ev' => EvDriver::class, 'event' => EventDriver::class, 'uv' => UvDriver::class];
$driver = $options['driver'] ?? 'select';
// Raised only by workloads that hold idle connections open beside the load.
$maxConnections = filter_var($options['max-connections'] ?? 512, FILTER_VALIDATE_INT);
$headerTimeout = filter_var($options['header-timeout'] ?? 10, FILTER_VALIDATE_FLOAT);
$source = realpath($options['source'] ?? dirname(__DIR__) . '/src');
if (!in_array($protocol, ['http', 'ws', 'websocket'], true) || $port < 1 || $port > 65535 || $workers < 1
    || !in_array($responseMode, ['reuse', 'fresh', 'string'], true) || !isset($drivers[$driver])
    || $maxConnections < 1 || $headerTimeout === false || $headerTimeout <= 0
    || $source === false || !is_file($source . '/Runtime.php')) {
    fwrite(STDERR, "Usage: php server.php --protocol=http|ws --host=127.0.0.1 --port=8080 --workers=1 [--source=/snapshot/src] [--http-response=reuse|fresh|string] [--driver=select|ev|event|uv] [--max-connections=512] [--header-timeout=10] [--metadata]\n");
    exit(2);
}
if ($driver !== 'select' && !$drivers[$driver]::isSupported()) {
    fwrite(STDERR, "The {$driver} driver needs the {$driver} extension, for example: php -d extension={$driver} server.php ...\n");
    exit(2);
}
if (isset($options['source'])) {
    $prefix = 'Crab\\Engine\\';
    $loader->setPsr4($prefix, [$source]);
    // Optimized Composer class maps take precedence over PSR-4 paths.
    $classes = [];
    foreach ($loader->getClassMap() as $class => $file) {
        if (str_starts_with($class, $prefix)) {
            $classes[$class] = $source . '/' . str_replace('\\', '/', substr($class, strlen($prefix))) . '.php';
        }
    }
    $loader->addClassMap($classes);
}
if (isset($options['metadata'])) {
    $loaded = [];
    $classes = [Runtime::class, Crab\Engine\Network\Connection::class, Crab\Engine\Http\Parser::class,
        Crab\Engine\Http\ResponseEncoder::class, Crab\Engine\Http\HttpSession::class, Request::class, Response::class,
        $protocol === 'http' ? HttpServer::class : WebSocketServer::class];
    foreach ($classes as $class) {
        $loaded[$class] = (new ReflectionClass($class))->getFileName();
    }
    echo 'CRAB_SERVER_METADATA ' . json_encode(['source_root' => $source, 'loaded_classes' => $loaded,
        'http_response' => $protocol === 'http' ? $responseMode : null, 'driver' => $drivers[$driver]], JSON_THROW_ON_ERROR) . "\n";
}
$runtime = new Runtime(static fn() => new $drivers[$driver]());
if ($protocol === 'http') {
    $hello = Response::text("Hello from Crab\n");
    $health = Response::text("ok\n");
    $handler = static function (Request $request) use ($hello, $health, $responseMode): Response|string {
        return match ($request->path()) {
            '/health' => $health,
            '/stream' => new Response(200, ['Content-Type' => 'application/octet-stream'], (static function (): Generator {
                $chunk = str_repeat('x', 16384);
                for ($i = 0; $i < 64; ++$i) { yield $chunk; }
            })()),
            default => match ($responseMode) {
                'fresh' => Response::text("Hello from Crab\n"),
                'string' => "Hello from Crab\n",
                default => $hello,
            },
        };
    };
    $server = new HttpServer($handler, maxRequests: 1000000, headerTimeout: $headerTimeout);
} else {
    $server = new WebSocketServer(static function (WebSocketSession $session, string $message, MessageType $type): void {
        $accepted = $type === MessageType::Text ? $session->sendText($message) : $session->sendBinary($message);
        if ($accepted === WriteResult::Backpressured) { $session->close(1013, 'Slow receiver'); }
    });
}
$runtime->group(workers: $workers, maxConnections: $maxConnections)->listen("tcp://{$host}:{$port}", $server);
$runtime->run(singleProcess: $workers === 1);
