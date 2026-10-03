# Actix codec lessons for Crab — 2026-10-03

The subsequent [HTTP codec optimization](HTTP-CODEC-OPTIMIZATION.md) implements
and measures the Date, absent-token and response-metadata ideas below. These
notes preserve the original hypotheses and their source references.

The [HTTP layer measurements](results/compare-http-headroom.json)
show a larger drop from the restricted transport responder to Crab's real HTTP
codec than from that codec to the full HTTP session. This justifies inspecting
encoding and parsing first. These layers are not a CPU cost decomposition:
the codec fixture also scans header boundaries, and only the restricted
responders cache Date. See [experiment limitations](HTTP-HEADROOM.md) and the
[earlier diagnostic profile](HTTP-PROFILE.md).

This review inspected official source on 2026-10-03, pinned to:

- `actix-codec` 0.5.4, actix-net commit
  [`f4f97bd952bd850c0a78b7d0b1c7581d7df137d9`](https://github.com/actix/actix-net/blob/f4f97bd952bd850c0a78b7d0b1c7581d7df137d9/actix-codec/Cargo.toml).
- `actix-http` 3.18.12, actix-web commit
  [`714572c5eca7a012b771dd9a978767e7573697a8`](https://github.com/actix/actix-web/blob/714572c5eca7a012b771dd9a978767e7573697a8/actix-http/Cargo.toml).

`actix-codec` supplies generic framed I/O and re-exports Tokio's `Decoder` and
`Encoder` traits. HTTP parsing, payload framing and response encoding live in
`actix-http`. The relevant optimization is inside those implementations; adding
a generic codec interface alone would not remove Crab's work.
[Generic exports](https://github.com/actix/actix-net/blob/f4f97bd952bd850c0a78b7d0b1c7581d7df137d9/actix-codec/src/lib.rs),
[HTTP codec](https://github.com/actix/actix-web/blob/714572c5eca7a012b771dd9a978767e7573697a8/actix-http/src/h1/codec.rs).

## 1. Share formatting whose value changes infrequently

Actix HTTP's Date service retains a formatted 29-byte date and refreshes it on
a 500 ms interval. Its encoder uses the service when the response has no Date
header. This avoids formatting a date separately for every response.
[Date service](https://github.com/actix/actix-web/blob/714572c5eca7a012b771dd9a978767e7573697a8/actix-http/src/date.rs),
[encoder](https://github.com/actix/actix-web/blob/714572c5eca7a012b771dd9a978767e7573697a8/actix-http/src/h1/encoder.rs).

**PHP hypothesis:** in [ResponseEncoder](../src/Http/ResponseEncoder.php), try a
process-local, lazily refreshed Date string keyed by `time()`. Format that same
second only when it changes; retain explicitly supplied Date headers. This
needs no recurring timer. Keep timeout deadlines on the existing monotonic
clock. Validate rollover and backward clock changes, then measure the lookup
cost against the saved `gmdate()` calls. This is the smallest first experiment;
it does not assume Date accounts for the whole codec gap.

## 2. Validate metadata once, keep framing decisions explicit

Actix's HTTP encoder writes typed header names and values directly into its
output buffer. Its decoder builds those types from already checked input and
derives connection state while processing headers. The codec retains the
request version, HEAD flag and connection type for response framing.
[Header encoding](https://github.com/actix/actix-web/blob/714572c5eca7a012b771dd9a978767e7573697a8/actix-http/src/h1/encoder.rs),
[header decoding](https://github.com/actix/actix-web/blob/714572c5eca7a012b771dd9a978767e7573697a8/actix-http/src/h1/decoder.rs),
[framing state](https://github.com/actix/actix-web/blob/714572c5eca7a012b771dd9a978767e7573697a8/actix-http/src/h1/codec.rs).

**PHP hypothesis:** Crab currently joins each response header into a field and
calls `Parser::field()` every time it encodes even the same readonly
[Response](../src/Http/Response.php). First measure retaining successful header
normalization for that object's lifetime. Preserve validation and its error
timing; constructor validation would change when exceptions become visible.
Confirm ownership, including externally referenced array elements, before
assuming `readonly` makes header values safe to cache indefinitely.
This cache is a proposed PHP adaptation, not a claim that Actix caches complete
responses. Compare newly allocated Responses as well as reused ones, because
cache lookup and storage can outweigh savings when each object is used once.

Keep Date, HTTP version, HEAD/bodyless status handling, connection closure and
body framing dynamic. Never cache iterable body output. An even smaller
candidate is skipping Connection token parsing when that header is absent;
retain all explicit close and HTTP/1.0 behavior. A fast path must preserve the
same HTTP semantics, including invalid-header rejection.

## 3. Preserve incremental state and buffer ownership

Actix's HTTP decoder returns `None` for incomplete input and splits consumed
bytes from `BytesMut`; payload decoding retains its framing state. `Framed`
owns read/write buffers, attempts buffered decoding before reading more, keeps
unwritten bytes after partial writes, and transfers both buffers when replacing
the codec. Its 8 KiB write readiness threshold is not a hard maximum frame size.
[HTTP decoder](https://github.com/actix/actix-web/blob/714572c5eca7a012b771dd9a978767e7573697a8/actix-http/src/h1/decoder.rs),
[Framed implementation](https://github.com/actix/actix-net/blob/f4f97bd952bd850c0a78b7d0b1c7581d7df137d9/actix-codec/src/framed.rs).

Crab's [Parser](../src/Http/Parser.php) already retains framing state and signals
incomplete input with `null`. Its [Connection](../src/Network/Connection.php)
already preserves partial writes and rejects writes beyond its hard capacity.
Keep those contracts, bounded producer chunks, and the exact HTTP-to-WebSocket
unread-byte handoff. A rejected write must not advance the response producer.

**Conditional PHP hypothesis:** only if profiling identifies buffer copying,
compare a consumed offset plus occasional compaction with repeated tail
`substr()` calls. Bound unread bytes and retained allocations, preserve split
delimiters and upgrade bytes, and include fragmented bodies and pipelines in
validation. PHP strings do not provide Rust's `BytesMut` ownership operations;
this offers no zero-copy guarantee. It is lower priority for tiny bodyless
requests than the Date and repeated-metadata experiments.

Run each candidate separately with matched trials, recording throughput, tail
latency, errors and CPU estimates. Retain changes only when their gains survive
repetition and protocol checks. These source-backed ideas require neither an
Actix dependency nor a new networking architecture, and imply no Rust-equivalent
performance claim.
