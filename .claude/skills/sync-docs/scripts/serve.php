<?php

declare(strict_types=1);

/**
 * Serves docs/ and opens it in the browser. Run from the framework's root:
 *
 *     composer docs                 # http://127.0.0.1:8900, opened for you
 *     composer docs -- --no-open    # serve only
 *     composer docs -- --port=9000
 *
 * Ctrl+C stops it.
 */

$root = getcwd();
if (!is_file($root . '/docs/index.html')) {
    fwrite(STDERR, "Run this from the framework's root, the folder with composer.json and docs/.\n");
    exit(2);
}

$port = 8900;
$open = true;
foreach (array_slice($argv, 1) as $argument) {
    if ($argument === '--no-open') {
        $open = false;
    } elseif (preg_match('/^--port=(\d+)$/', $argument, $match) === 1) {
        $port = (int) $match[1];
    } else {
        fwrite(STDERR, "Unknown option {$argument}. The options are --no-open and --port=9000.\n");
        exit(2);
    }
}
$url = "http://127.0.0.1:{$port}";

/**
 * Opens an address in the default browser, where the system has a way to.
 */
function openInBrowser(string $url): void
{
    $command = match (PHP_OS_FAMILY) {
        'Darwin' => 'open',
        'Windows' => 'start ""',
        default => 'xdg-open',
    };
    @exec($command . ' ' . escapeshellarg($url) . (PHP_OS_FAMILY === 'Windows' ? '' : ' > /dev/null 2>&1 &'));
}

// Something already listens there: most likely this script, started earlier.
$taken = @fsockopen('127.0.0.1', $port, $code, $message, 0.3);
if ($taken !== false) {
    fclose($taken);
    echo "Port {$port} is already in use, so no server was started. If it is the docs, they are at {$url}\n";
    echo "Otherwise choose another port: composer docs -- --port=9000\n";
    if ($open) {
        openInBrowser($url);
    }
    exit(0);
}

echo "Crab docs at {$url}\n";
echo "Press Ctrl+C to stop\n";

$server = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:{$port}", '-t', $root . '/docs'],
    // Its request log is noise here; what it fails with still shows.
    [STDIN, ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w'], ['file', PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null', 'w']],
    $pipes,
);
if ($server === false) {
    fwrite(STDERR, "The web server could not be started.\n");
    exit(1);
}

if ($open) {
    // Once it answers, and not before, so that the browser does not open on an error page.
    for ($try = 0; $try < 50; ++$try) {
        $ready = @fsockopen('127.0.0.1', $port, $code, $message, 0.1);
        if ($ready !== false) {
            fclose($ready);
            openInBrowser($url);
            break;
        }
        usleep(100_000);
    }
}

exit(proc_close($server));
