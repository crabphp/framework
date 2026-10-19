<?php

declare(strict_types=1);

namespace Crab\Http;

use Crab\Contracts\Http\Middleware;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;

/**
 * Says which proxies stand in front of the application, so that a
 * request is known by the client's address and not the proxy's:
 *
 *     ->withMiddleware(prepend: [new TrustedProxies(['10.0.0.0/8', '127.0.0.1'])])
 *
 * A proxy passes the client's address on in X-Forwarded-For, which
 * anyone can also write themselves. So the header is believed only on a
 * request that comes from a proxy named here, and read from the right:
 * the first address that is not one of the proxies is the client.
 * Without this middleware the header is not read at all.
 */
final readonly class TrustedProxies implements Middleware
{
    /**
     * The request attribute the client's address is kept under; Request::ip() reads it.
     */
    public const ATTRIBUTE = 'crab.client_ip';

    /**
     * @param list<string> $proxies Addresses, or ranges such as "10.0.0.0/8" and "fd00::/8".
     */
    public function __construct(
        private array $proxies,
    ) {
        foreach ($proxies as $proxy) {
            [$address, $bits] = [...explode('/', $proxy, 2), null];
            $packed = @inet_pton($address);
            // A mask that is not a number of bits the address has would be read as /0: everyone.
            if (
                $packed === false
                || $bits !== null && (preg_match('/^\d{1,3}$/D', $bits) !== 1 || (int) $bits > (strlen($packed) * 8))
            ) {
                throw new \InvalidArgumentException(
                    "\"{$proxy}\" is not an address or a range of addresses, such as 10.0.0.0/8.",
                );
            }
        }
    }

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $peer = (string) ($request->getServerParams()['REMOTE_ADDR'] ?? '');
        if ($peer === '' || !$this->trusts($peer)) {
            return $handler->handle($request);
        }
        $client = $peer;
        $hops = array_filter(
            array_map(trim(...), explode(',', $request->getHeaderLine('X-Forwarded-For'))),
            static fn(string $hop): bool => $hop !== '',
        );
        foreach (array_reverse($hops) as $hop) {
            if (@inet_pton($hop) === false) {
                break;
            }
            $client = $hop;
            if (!$this->trusts($hop)) {
                break;
            }
        }
        return $handler->handle($request->withAttribute(self::ATTRIBUTE, $client));
    }

    private function trusts(string $address): bool
    {
        $packed = @inet_pton($address);
        if ($packed === false) {
            return false;
        }
        foreach ($this->proxies as $proxy) {
            [$network, $bits] = [...explode('/', $proxy), null];
            $range = (string) inet_pton($network);
            if (strlen($range) !== strlen($packed)) {
                continue;
            }
            $bits = $bits === null ? strlen($range) * 8 : (int) $bits;
            $whole = intdiv($bits, 8);
            $rest = $bits % 8;
            if (substr($packed, 0, $whole) !== substr($range, 0, $whole)) {
                continue;
            }
            if ($rest === 0 || (ord($packed[$whole]) >> (8 - $rest)) === (ord($range[$whole]) >> (8 - $rest))) {
                return true;
            }
        }
        return false;
    }
}
