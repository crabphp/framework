<?php

declare(strict_types=1);

$root = trim(file_get_contents(__DIR__ . '/root.txt'));
require __DIR__ . '/Probe.php';
$loader = require $root . '/vendor/autoload.php';
$loader->setPsr4('Crab\\Engine\\', __DIR__ . '/src');
$loader->setPsr4('Revolt\\', __DIR__ . '/revolt');
$map = [];
foreach ($loader->getClassMap() as $class => $path) {
    if (str_starts_with($class, 'Crab\\Engine\\') && !str_starts_with($class, 'Crab\\Engine\\Tests\\')) {
        $map[$class] = __DIR__ . '/src/' . str_replace('\\', '/', substr($class, strlen('Crab\\Engine\\'))) . '.php';
    } elseif (str_starts_with($class, 'Revolt\\')) {
        $map[$class] = __DIR__ . '/revolt/' . str_replace('\\', '/', substr($class, strlen('Revolt\\'))) . '.php';
    }
}
$loader->addClassMap($map);
CrabProfile\Probe::start();
require $root . '/benchmarks/server.php';
