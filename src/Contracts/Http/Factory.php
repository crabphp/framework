<?php

declare(strict_types=1);

namespace Crab\Contracts\Http;

use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\ServerRequestFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Http\Message\UploadedFileFactoryInterface;
use Psr\Http\Message\UriFactoryInterface;

/**
 * Makes HTTP messages and their parts: createResponse(), createStream(),
 * createUri() and the rest. It is the PSR-17 factories in one, under
 * Crab's own name; code that needs one of them alone can ask for that
 * PSR-17 interface.
 */
interface Factory extends
    ResponseFactoryInterface,
    ServerRequestFactoryInterface,
    StreamFactoryInterface,
    UploadedFileFactoryInterface,
    UriFactoryInterface
{
}
