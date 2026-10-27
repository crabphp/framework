<?php

declare(strict_types=1);

// Every setting of this section, at its default. Delete a line to go back to the default.
return [
    // Put before every relative URL of the default client, and added to every request it sends.
    'base_url' => '',
    'headers' => [],

    // Seconds a whole call may take, retries included, and connecting may take.
    'timeout' => 30.0,
    'connect_timeout' => 5.0,

    // Redirects followed, 0 returning the redirect itself; and HTTP/1.1 connections to one server.
    'redirects' => 5,
    'connections' => 8,

    // PHP's "ssl" context options, such as ['cafile' => '/path/ca.pem'].
    'tls' => [],

    // A proxy every request goes through: "http://proxy.example:8080", with "user:password@" before the host when it asks.
    'proxy' => null,

    // More tries for a request that may be tried again.
    'retries' => 0,

    // Whether HTTP/2 is used with servers that offer it.
    'http2' => true,

    // The most bytes of body an answer may have, 64 MiB, unless a request gives its own maxBytes:.
    'max_bytes' => 67_108_864,
];
