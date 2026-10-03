<?php

declare(strict_types=1);

/**
 * In-process request path: user-mode CPU per request, without a load
 * generator, kernel networking time or the event loop's own dispatch.
 *
 * One connection over a socket pair receives a browser-sized request whose
 * query string and one field value change every time, as benchmarks/compare.py
 * sends with --http-request=browser-unique. The script writes the request,
 * calls the connection's read turn directly and reads the response back.
 * It is for comparing two versions of the engine quickly; throughput belongs
 * to the wrk comparisons. A --source snapshot must answer inside the read
 * turn, which holds from the HTTP fast path onward.
 */

$loader = require dirname(__DIR__) . '/vendor/autoload.php';

use Crab\Engine\Http\Request;
use Crab\Engine\Http\Response;
use Crab\Engine\Network\Connection;
use Crab\Engine\Server\HttpServer;
use Revolt\EventLoop;
use Revolt\EventLoop\Driver\StreamSelectDriver;

$options = getopt('', ['source:', 'iterations:', 'http-response:', 'trials:']);
$source = realpath($options['source'] ?? dirname(__DIR__) . '/src/Engine');
$iterations = filter_var($options['iterations'] ?? 300000, FILTER_VALIDATE_INT);
$trials = filter_var($options['trials'] ?? 5, FILTER_VALIDATE_INT);
$mode = $options['http-response'] ?? 'fresh';
if ($source === false || !is_file($source . '/Runtime.php') || $iterations < 1 || $trials < 1
    || !in_array($mode, ['reuse', 'fresh', 'string'], true)) {
    fwrite(STDERR, "Usage: php request-path.php [--source=/snapshot/src] [--iterations=300000] [--trials=5] [--http-response=reuse|fresh|string]\n");
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

$requests = [];
for ($index = 1; $index <= 997; ++$index) {
    $requests[] = "GET /?r={$index} HTTP/1.1\r\nHost: 127.0.0.1:8080\r\nConnection: keep-alive\r\n"
        . "sec-ch-ua: \"Chromium\";v=\"128\", \"Not;A=Brand\";v=\"24\"\r\nsec-ch-ua-mobile: ?0\r\nsec-ch-ua-platform: \"macOS\"\r\n"
        . "Upgrade-Insecure-Requests: 1\r\nUser-Agent: Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/128.0.0.0 Safari/537.36\r\n"
        . "Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/avif,image/webp,*/*;q=0.8\r\n"
        . "Sec-Fetch-Site: none\r\nSec-Fetch-Mode: navigate\r\nSec-Fetch-User: ?1\r\nSec-Fetch-Dest: document\r\n"
        . "Accept-Encoding: gzip, deflate, br, zstd\r\nAccept-Language: en-US,en;q=0.9\r\nX-Request-Id: request-{$index}\r\n"
        . "Cookie: session=abcdef0123456789abcdef0123456789; theme=dark; _ga=GA1.1.123456789.1700000000\r\n\r\n";
}

$driver = new StreamSelectDriver();
EventLoop::setDriver($driver);
$pair = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
$peer = $pair[1];
$connection = new Connection($driver, $pair[0]);
$hello = Response::text("Hello from Crab\n");
(new HttpServer(static function (Request $request) use ($hello, $mode): Response|string {
    return match ($request->path()) {
        '/health' => $hello,
        default => match ($mode) {
            'fresh' => Response::text("Hello from Crab\n"),
            'string' => "Hello from Crab\n",
            default => $hello,
        },
    };
}, maxRequests: PHP_INT_MAX))->attach($connection);
$read = Closure::bind(function (): void { $this->read(); }, $connection, Connection::class);

$userMicroseconds = static function (): float {
    $usage = getrusage();
    return $usage['ru_utime.tv_sec'] * 1e6 + $usage['ru_utime.tv_usec'];
};
$serve = static function (int $count) use ($requests, $peer, $read): int {
    $bytes = 0;
    for ($index = 0; $index < $count; ++$index) {
        fwrite($peer, $requests[$index % 997]);
        $read();
        $bytes += strlen(fread($peer, 8192));
    }
    return $bytes;
};
// The same four stream calls without the engine, to subtract the harness.
$echo = stream_socket_pair(STREAM_PF_UNIX, STREAM_SOCK_STREAM, 0);
$reply = str_repeat('x', 157);
$idle = static function (int $count) use ($requests, $echo, $reply): void {
    for ($index = 0; $index < $count; ++$index) {
        fwrite($echo[1], $requests[$index % 997]);
        fread($echo[0], 65536);
        fwrite($echo[0], $reply);
        fread($echo[1], 8192);
    }
};

$serve(20000);
$idle(20000);
$total = INF;
$harness = INF;
$responseBytes = 0;
for ($trial = 0; $trial < $trials; ++$trial) {
    $before = $userMicroseconds();
    $idle($iterations);
    $harness = min($harness, ($userMicroseconds() - $before) / $iterations);
    $before = $userMicroseconds();
    $responseBytes = intdiv($serve($iterations), $iterations);
    $total = min($total, ($userMicroseconds() - $before) / $iterations);
}
echo json_encode([
    'source' => $source,
    'http_response' => $mode,
    'iterations' => $iterations,
    'trials' => $trials,
    'request_bytes' => strlen($requests[0]),
    'response_bytes' => $responseBytes,
    'user_us_per_request' => round($total, 3),
    'harness_stream_calls_us' => round($harness, 3),
    'engine_user_us_per_request' => round($total - $harness, 3),
    'php' => PHP_VERSION,
    'jit' => function_exists('opcache_get_status') ? (opcache_get_status(false)['jit']['on'] ?? false) : false,
    'note' => 'Minimum over trials; user-mode CPU only. Compare two sources in alternating runs.',
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n";
