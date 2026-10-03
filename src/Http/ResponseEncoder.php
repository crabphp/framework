<?php

declare(strict_types=1);

namespace Crab\Engine\Http;

/**
 * Owns response framing, including HEAD and bodyless status semantics.
 */
final class ResponseEncoder
{
    private static ?int $dateSecond = null;
    private static string $date = '';
    public readonly bool $close;
    private string $head;
    private bool $chunked = false;
    private bool $suppressBody;
    private ?int $expectedLength = null;

    public function __construct(
        private readonly Response $response,
        Request $request,
        bool $forceClose = false,
        bool $upgrade = false,
    ) {
        if ($response->status < 200 && !($upgrade && $response->status === 101)) {
            throw new \InvalidArgumentException('A final response must have status >= 200; use Upgrade for 101.');
        }
        $headers = $response->normalizedHeaders();
        if (isset($headers['transfer-encoding'])) {
            throw new \InvalidArgumentException('Response transfer encoding is owned by the server.');
        }
        if (isset($headers['content-length'])) {
            $lengths = $headers['content-length'];
            if (count($lengths) !== 1 || !preg_match('/^[0-9]+$/D', $lengths[0])
                || strlen(ltrim($lengths[0], '0')) > 18) {
                throw new \InvalidArgumentException('Invalid response Content-Length.');
            }
            $this->expectedLength = (int) $lengths[0];
        }
        $bodyless = $response->status < 200 || in_array($response->status, [204, 205, 304], true);
        $this->suppressBody = $request->method === 'HEAD' || $bodyless;
        if ($response->status < 200 || $response->status === 204) {
            unset($headers['content-length']);
            $this->expectedLength = null;
        } elseif ($response->status === 205) {
            $headers['content-length'] = ['0'];
            $this->expectedLength = 0;
        } elseif ($request->method === 'HEAD' && $this->expectedLength !== null) {
            // HEAD may describe a representation the handler did not materialize.
        } elseif ($response->status !== 304 && is_string($response->body)) {
            $length = strlen($response->body);
            if ($this->expectedLength !== null && $this->expectedLength !== $length) {
                throw new \InvalidArgumentException('Response body does not match Content-Length.');
            }
            $this->expectedLength = $length;
            $headers['content-length'] = [(string) $length];
        } elseif (!$this->suppressBody && $this->expectedLength === null && $request->version === '1.1') {
            $this->chunked = true;
            $headers['transfer-encoding'] = ['chunked'];
        }

        $this->close = !$upgrade && ($forceClose || !$request->keepAlive()
            || (isset($headers['connection']) && in_array('close',
                array_map(trim(...), explode(',', strtolower(implode(',', $headers['connection'])))), true))
            || (!$this->suppressBody && $this->expectedLength === null && !$this->chunked));
        if (!$upgrade) {
            unset($headers['upgrade']);
            $headers['connection'] = [$this->close ? 'close' : 'keep-alive'];
        }
        if (!isset($headers['date'])) {
            $headers['date'] = [self::date()];
        }
        $this->head = 'HTTP/' . $request->version . ' ' . $response->status . ' ' . Response::reason($response->status) . "\r\n";
        foreach ($headers as $name => $values) {
            foreach ($values as $value) {
                $this->head .= $name . ': ' . $value . "\r\n";
                if (strlen($this->head) > 16384) {
                    throw new \LengthException('Response headers exceed 16 KiB.');
                }
            }
        }
        $this->head .= "\r\n";
    }

    /**
     * Encodes a small fixed-length response in one pass, producing the same
     * bytes as the general encoder, or returns null when that encoder is
     * required. $close carries a forced close in and the final decision out.
     *
     * @internal
     */
    public static function small(Response $response, Request $request, bool &$close): ?string
    {
        $body = $response->body;
        $status = $response->status;
        if (!is_string($body) || $status < 200 || $status === 204 || $status === 205 || $status === 304
            || $request->method === 'HEAD') {
            return null;
        }
        $block = $response->headerBlock();
        $length = strlen($body);
        // Leaves room for the status line and generated fields within 8 KiB.
        if ($block === null || strlen($block) + $length > 7936) {
            return null;
        }
        $close = $close || !$request->keepAlive();
        $version = $request->version;
        $reason = Response::reason($status);
        $connection = $close ? 'close' : 'keep-alive';
        $date = self::date();
        return 'HTTP/' . $version . ' ' . $status . ' ' . $reason . "\r\n" . $block
            . 'content-length: ' . $length . "\r\nconnection: " . $connection . "\r\ndate: " . $date . "\r\n\r\n" . $body;
    }

    /**
     * Current HTTP date, formatted once per observed wall-clock second.
     */
    private static function date(): string
    {
        $second = time();
        if (self::$dateSecond !== $second) {
            self::$dateSecond = $second;
            self::$date = gmdate('D, d M Y H:i:s', $second) . ' GMT';
        }
        return self::$date;
    }

    /**
     * The complete message when it fits one bounded write; null means the
     * response must be produced through bytes().
     */
    public function message(): ?string
    {
        if ($this->suppressBody) {
            return $this->head;
        }
        // Small fixed responses can reach the socket in one write without
        // constructing a second large copy for streamed or sizeable bodies.
        if (is_string($this->response->body) && strlen($this->head) + strlen($this->response->body) <= 8192) {
            return $this->head . $this->response->body;
        }
        return null;
    }

    /**
     * @return \Generator<int, string>
     */
    public function bytes(): \Generator
    {
        $message = $this->message();
        if ($message !== null) {
            yield $message;
            return;
        }
        yield $this->head;
        if ($this->suppressBody) {
            return;
        }
        if (is_string($this->response->body)) {
            if ($this->response->body !== '') {
                yield $this->response->body;
            }
            return;
        }
        $written = 0;
        foreach ($this->response->body as $chunk) {
            if (!is_string($chunk)) {
                throw new \UnexpectedValueException('Response producers must yield strings.');
            }
            $length = strlen($chunk);
            if ($length > 65536) {
                throw new \LengthException('Response producers must yield chunks no larger than 64 KiB.');
            }
            if ($length === 0) {
                continue;
            }
            $written += $length;
            if ($this->expectedLength !== null && $written > $this->expectedLength) {
                throw new \UnexpectedValueException('Response producer exceeded Content-Length.');
            }
            if ($this->chunked) {
                yield dechex($length) . "\r\n";
            }
            yield $chunk;
            if ($this->chunked) {
                yield "\r\n";
            }
        }
        if ($this->expectedLength !== null && $written !== $this->expectedLength) {
            throw new \UnexpectedValueException('Response producer did not satisfy Content-Length.');
        }
        if ($this->chunked) {
            yield "0\r\n\r\n";
        }
    }
}
