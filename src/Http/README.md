# HTTP/1 server module

`Server\HttpServer` is a connection handler for `WorkerGroup::listen()`. A handler receives an immutable `Request` and returns a `Response`, `Upgrade`, or text string. Handlers run one at a time per connection, including when a handler suspends through Revolt. Response order matches request order.

Pass `Request::$cancellation` into `Async::sleep()`, `Task::await()` and other cooperative operations. The session cancels it on request completion, timeout, upgrade or connection teardown, allowing suspended handlers to release their resources. The worker retains HTTP handler and response-producer activities until they actually exit, including asynchronous `finally` cleanup after a socket closes. Arbitrary operations that ignore cancellation remain bounded by the worker shutdown deadline. Independently running or long-lived work should use `Worker::spawn()` so its ownership is explicit.

```php
$http = new HttpServer(static function (Request $request): Response {
    return new Response(200, ['Content-Type' => 'application/octet-stream'], $request->body->chunks());
}, maxBodyBytes: 16 * 1024 * 1024);
$runtime->group()->listen('tcp://127.0.0.1:8080', $http);
```

Request headers and trailers are separate lowercase maps of field names to lists of values. `header()` joins values; `hasToken()` handles token-list membership. `body` is a seekable `Body` with `size()`, `read()`, `rewind()`, `chunks()` and `contents()`. The parser incrementally decodes the upload into a temporary stream, spilling beyond 64 KiB. It invokes the handler after the complete body has arrived. This gives bounded-memory upload storage and streaming reads afterward; it does not expose a live, partially received upload to the handler. `contents()` explicitly materializes the configured bounded body.

Both Content-Length and chunked requests are supported, including bounded chunk extensions and trailers. Strict framing rejects duplicate Content-Length (including identical duplicates), Content-Length together with Transfer-Encoding, malformed field syntax, obsolete folding, invalid Host fields, invalid chunks, and framing/authentication fields in trailers. HTTP/1.1 requires exactly one Host. Only the `chunked` transfer coding is decoded; other codings return 501. CONNECT tunneling is unsupported and returns 501. Request Content-Encoding is preserved; decompression is an application choice. Unknown expectations return 417; `100-continue` works without requiring the client to transmit its body first.

The default limits are 16 KiB of request headers, 100 fields, a 1 MiB decoded body, 1,000 requests per connection, a 10-second header deadline, a 30-second body deadline, a 30-second keep-alive idle timeout, and a 30-second response deadline. Header/body deadlines are absolute for that phase, so a slow trickle does not reset them. Timeouts and byte limits are configurable in `HttpServer`. Blocking PHP code cannot be preempted by these cooperative timers.

Response framing belongs to the server. Do not set Transfer-Encoding. Strings receive Content-Length; iterable bodies use HTTP/1.1 chunked encoding unless an explicit Content-Length is supplied and satisfied. HTTP/1.0 streaming without a known length closes the connection. Producers yield string chunks up to 64 KiB and advance only while output capacity permits. HEAD, 101, 204, 205 and 304 suppress body transmission according to their semantics. Response headers are limited to 16 KiB and validated against injection.

`Upgrade` requires a bodyless 101 response and an upgrade request. Its callback receives `(Connection, Request, string $unreadBytes)` after HTTP has accepted the complete response into the ordered connection output, retired its timers/parser ownership, and transferred unread input exactly once. The callback installs the new protocol handlers. WebSocket uses this hook.

Peer EOF does not discard complete pipelined requests. Graceful drain stops accepting further requests, finishes the active response, then closes. A handler failure produces 500 before response headers begin; failure after output begins closes the connection. HTTP/2, HTTP/3, response compression, and routing are outside this module.

Run `composer test -- --filter HttpTest` from `engine/` for parser, framing, timeout, body-spool, socket lifecycle, pressure, pipeline and upgrade checks.
