<?php

$loaders = Composer\Autoload\ClassLoader::getRegisteredLoaders();
$loader = reset($loaders);
$status = opcache_get_status(false);
return [
    'php' => PHP_VERSION,
    'sapi' => PHP_SAPI,
    'classmap_authoritative' => $loader->isClassMapAuthoritative(),
    'memory_limit' => ini_get('memory_limit'),
    'debug' => false,
    'pid' => getmypid(),
    'opcache' => ini_get('opcache.enable'),
    'timestamps' => ini_get('opcache.validate_timestamps'),
    'jit' => $status['jit'] ?? null,
    'opcache_statistics' => $status['opcache_statistics'] ?? null,
    'cache_full' => $status['cache_full'] ?? null,
];
