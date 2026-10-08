<?php

declare(strict_types=1);

namespace Crab\HttpClient;

use Psr\Http\Client\ClientExceptionInterface;

/**
 * A request that got no usable answer. Its message names the method, the
 * host and the path, never the query string or a header, which may hold
 * a secret.
 */
class ClientException extends \RuntimeException implements ClientExceptionInterface
{
}
