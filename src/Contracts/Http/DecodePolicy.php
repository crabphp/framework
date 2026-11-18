<?php

declare(strict_types=1);

namespace Crab\Contracts\Http;

/**
 * Chooses how a request's query string and body are read, before any
 * middleware can read them: a package whose requests must be read
 * strictly adds one to DecodePolicies. It decides from the request's
 * method, path and headers alone, and reads neither its query string nor
 * its body, which would be reading them the ordinary way.
 */
interface DecodePolicy
{
    /**
     * The strict reading for this request, or null to leave it as it is.
     */
    public function select(Request $request): ?Decoding;
}
