<?php

declare(strict_types=1);

$root = isset($argv[1]) ? realpath($argv[1]) : false;
if ($argc !== 2 || $root === false || !is_file($root . '/vendor/autoload.php')) {
    fwrite(STDERR, "Usage: php /temporary/directory/prepare.php /absolute/path/to/engine\n");
    exit(2);
}
if (__DIR__ === $root || str_starts_with(__DIR__, $root . DIRECTORY_SEPARATOR)) {
    throw new RuntimeException('Copy the profiler files into a temporary directory outside the engine before preparing.');
}
require $root . '/vendor/autoload.php';
if (!class_exists(PhpParser\ParserFactory::class)) {
    throw new RuntimeException('Install engine development dependencies: nikic/php-parser is supplied through PHPUnit.');
}
$parser = (new PhpParser\ParserFactory())->createForNewestSupportedVersion();
$finder = new PhpParser\NodeFinder();
$manifest = ['source_root' => $root, 'created_utc' => gmdate('c'), 'source_sha256' => [], 'instrumented_methods' => []];
foreach ([$root . '/src' => __DIR__ . '/src', $root . '/vendor/revolt/event-loop/src' => __DIR__ . '/revolt'] as $source => $target) {
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        if (!$file->isFile()) {
            continue;
        }
        $relative = substr($file->getPathname(), strlen($source) + 1);
        $destination = $target . '/' . $relative;
        if (!is_dir(dirname($destination))) {
            mkdir(dirname($destination), 0777, true);
        }
        copy($file->getPathname(), $destination);
        $manifest['source_sha256'][substr($file->getPathname(), strlen($root) + 1)] = hash_file('sha256', $file->getPathname());
    }
}
$methods = [
    'src/Http/Parser.php' => ['append', 'next', 'parseHeaders', 'complete', 'field', 'chunkLength', 'takeLine'],
    'src/Http/Body.php' => ['append', 'rewind', '__destruct'],
    'src/Http/Request.php' => ['header', 'hasToken', 'keepAlive', 'path'],
    'src/Http/ResponseEncoder.php' => ['__construct'],
    'src/Http/HttpSession.php' => ['schedule', 'process', 'pump', 'armDeadline', 'checkDeadline', 'releaseRequest'],
    'src/Async/Cancellation.php' => ['cancel', 'throwIfCancelled', 'notify'],
    'src/Network/Connection.php' => ['read', 'tryWrite', 'flush', 'updateReadWatcher', 'beginActivity', 'settleIfReady'],
    'revolt/EventLoop/Internal/AbstractDriver.php' => ['defer', 'delay', 'cancel', 'enable', 'disable', 'onReadable', 'onWritable'],
    'revolt/EventLoop/Driver/StreamSelectDriver.php' => ['activate', 'deactivate', 'dispatch', 'selectStreams'],
    'revolt/EventLoop/Internal/TimerQueue.php' => ['insert', 'remove', 'extract'],
];
foreach ($methods as $relative => $names) {
    $path = __DIR__ . '/' . $relative;
    $source = file_get_contents($path);
    $nodes = $finder->findInstanceOf($parser->parse($source), PhpParser\Node\Stmt\ClassMethod::class);
    $edits = [];
    foreach ($nodes as $node) {
        $name = $node->name->toString();
        if (!in_array($name, $names, true)) {
            continue;
        }
        $label = pathinfo($path, PATHINFO_FILENAME) . '::' . $name;
        $start = strpos($source, '{', $node->getStartFilePos());
        $end = $node->getEndFilePos();
        if ($start === false || $start >= $end || $source[$end] !== '}') {
            throw new RuntimeException('Cannot locate method braces for ' . $label);
        }
        $edits[] = [$start + 1, "\n        \$__crabProfileMethod = \\CrabProfile\\Probe::enter('{$label}');\n        try {\n"];
        $edits[] = [$end, "\n        } finally {\n            \\CrabProfile\\Probe::leave(\$__crabProfileMethod);\n        }\n    "];
        $manifest['instrumented_methods'][] = $label;
    }
    usort($edits, static fn(array $left, array $right): int => $right[0] <=> $left[0]);
    foreach ($edits as [$position, $replacement]) {
        $source = substr_replace($source, $replacement, $position, 0);
    }
    file_put_contents($path, $source);
}

function span(string $label, string $statement, bool $expression = false): string
{
    $name = $expression ? $label : var_export($label, true);
    return "\$__crabProfileStatement = \\CrabProfile\\Probe::enter({$name});\ntry {\n{$statement}\n} finally {\n\\CrabProfile\\Probe::leave(\$__crabProfileStatement);\n}";
}

function replaceExact(string $relative, string $search, string $replacement): void
{
    $path = __DIR__ . '/' . $relative;
    $source = file_get_contents($path);
    if (substr_count($source, $search) !== 1) {
        throw new RuntimeException('Expected exactly one marker in ' . $relative . ': ' . $search);
    }
    file_put_contents($path, str_replace($search, $replacement, $source));
}

$markers = [
    ['src/Http/Parser.php', 'Parser.body.allocate', '$this->body = new Body();'],
    ['src/Http/Parser.php', 'Parser.request.construct', '$request = new Request($this->method, $this->target, $this->version, $this->headers, $this->body, $this->trailers);'],
    ['src/Http/HttpSession.php', 'HttpSession.handler', '$response = ($this->handler)($request);'],
    ['src/Network/Connection.php', 'IO.fwrite.immediate', '$written = @fwrite($this->stream, $bytes);'],
    ['src/Network/Connection.php', 'IO.fwrite.buffered', '$written = @fwrite($this->stream, $chunk, min(strlen($chunk), $remaining));'],
];
foreach ($markers as [$relative, $label, $statement]) {
    replaceExact($relative, $statement, span($label, $statement));
}
$readSource = file_get_contents(__DIR__ . '/src/Network/Connection.php');
$readSize = str_contains($readSource, '$bytes = @fread($this->stream, $readSize);')
    ? '$readSize' : 'min(self::READ_CHUNK_SIZE, $remaining)';
$statement = '$bytes = @fread($this->stream, ' . $readSize . ');';
replaceExact('src/Network/Connection.php', $statement, span('IO.fread', $statement)
    . "\n\\CrabProfile\\Probe::readResult(\$bytes, {$readSize});");

// Start inside the first argument and finish after construction: a skipped
// coalescing branch creates no span, and construction keeps its original trace.
$path = __DIR__ . '/src/Async/Cancellation.php';
$source = file_get_contents($path);
$nodes = $finder->findInstanceOf($parser->parse($source), PhpParser\Node\Expr\New_::class);
$edits = [];
$sites = 0;
foreach ($nodes as $node) {
    if (!$node->class instanceof PhpParser\Node\Name || $node->class->getLast() !== 'CancelledException') {
        continue;
    }
    if (!isset($node->args[0]) || $node->args[0]->unpack) {
        throw new RuntimeException('CancelledException profiling expects an explicit first message argument.');
    }
    $message = $node->args[0]->value;
    $edits[] = [$node->getStartFilePos(), '\\CrabProfile\\Probe::finishReason('];
    $edits[] = [$node->getEndFilePos() + 1, ')'];
    $edits[] = [$message->getStartFilePos(), '\\CrabProfile\\Probe::beginReason('];
    $edits[] = [$message->getEndFilePos() + 1, ')'];
    ++$sites;
}
if ($sites === 0) {
    throw new RuntimeException('No cancellation exception construction sites were found.');
}
usort($edits, static fn(array $left, array $right): int => $right[0] <=> $left[0]);
foreach ($edits as [$position, $replacement]) {
    $source = substr_replace($source, $replacement, $position, 0);
}
file_put_contents($path, $source);
$manifest['cancellation_construction_sites'] = $sites;
$statement = '$result = \\stream_select($read, $write, $except, $seconds, $microseconds);';
replaceExact('revolt/EventLoop/Driver/StreamSelectDriver.php', $statement,
    span("(\$seconds === 0 && \$microseconds === 0) ? 'IO.select.poll' : 'IO.select.wait'", $statement, true));
foreach (['valid' => 'generatorValid', 'current' => 'generatorCurrent', 'next' => 'generatorNext'] as $method => $helper) {
    replaceExact('src/Http/HttpSession.php', '$this->output->' . $method . '()', '\\CrabProfile\\Probe::' . $helper . '($this->output)');
}
$manifest['probe_sha256'] = hash_file('sha256', __DIR__ . '/Probe.php');
$manifest['prepare_sha256'] = hash_file('sha256', __FILE__);
$manifest['bootstrap_sha256'] = hash_file('sha256', __DIR__ . '/server.php');
$manifest['fixture_sha256'] = hash_file('sha256', $root . '/benchmarks/server.php');
file_put_contents(__DIR__ . '/manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
file_put_contents(__DIR__ . '/root.txt', $root);
echo 'Prepared ' . count($manifest['instrumented_methods']) . " method spans plus statement/generator spans under " . __DIR__ . "\n";
