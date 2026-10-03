#!/usr/bin/env node

// One outstanding masked binary message per connection. Every echo is validated.
import net from 'node:net';
import crypto from 'node:crypto';
import { performance } from 'node:perf_hooks';

const options = Object.fromEntries(process.argv.slice(2).map(argument => {
    const separator = argument.indexOf('=');
    if (!argument.startsWith('--') || separator < 0) throw new Error(`Invalid option: ${argument}`);
    return [argument.slice(2, separator), argument.slice(separator + 1)];
}));
const host = options.host ?? '127.0.0.1';
const port = Number(options.port ?? 8080);
const concurrency = Number(options.concurrency ?? 32);
const payloadBytes = Number(options.payload ?? 64);
const duration = Number(options.duration ?? 2);
const warmup = Number(options.warmup ?? 0.5);
if (![port, concurrency, payloadBytes].every(Number.isInteger) || port < 1 || concurrency < 1
    || payloadBytes < 8 || payloadBytes > 65535 || duration <= 0 || warmup < 0) {
    throw new Error('Invalid port, concurrency, payload, duration or warmup.');
}

let phase = 'connecting';
let ready = 0;
let closed = 0;
let offered = 0;
let completed = 0;
let errors = 0;
let firstError = null;
let start = 0;
let elapsed = 0;
let cpuStart;
let cpuEnd;
let sumLatency = 0;
let maxLatency = 0;
const histogram = new Uint32Array(2048);
const clients = [];
let finish;
const finished = new Promise(resolve => { finish = resolve; });
const watchdog = setTimeout(() => fail(new Error('Benchmark exceeded its deadline.')), (warmup + duration + 10) * 1000);

function fail(error) {
    errors++;
    firstError ??= error.message;
    phase = 'failed';
    for (const client of clients) client.socket.destroy();
    finish();
}

function maskedFrame(opcode, payload) {
    const headerBytes = payload.length < 126 ? 2 : 4;
    const frame = Buffer.allocUnsafe(headerBytes + 4 + payload.length);
    frame[0] = 0x80 | opcode;
    frame[1] = 0x80 | (headerBytes === 2 ? payload.length : 126);
    if (headerBytes === 4) frame.writeUInt16BE(payload.length, 2);
    crypto.randomFillSync(frame, headerBytes, 4);
    for (let i = 0; i < payload.length; i++) frame[headerBytes + 4 + i] = payload[i] ^ frame[headerBytes + (i & 3)];
    return frame;
}

function send(client) {
    if (phase !== 'warmup' && phase !== 'measured') return;
    client.sequence++;
    client.payload.writeBigUInt64BE(BigInt(client.id) * 0x100000000n + BigInt(client.sequence));
    const frame = maskedFrame(2, client.payload);
    client.pending = { started: performance.now(), measured: phase === 'measured' };
    if (phase === 'measured') offered++;
    client.socket.write(frame);
}

function close(client) {
    if (client.closing || client.socket.destroyed) return;
    client.closing = true;
    client.socket.write(maskedFrame(8, Buffer.from([0x03, 0xe8])));
}

function record(milliseconds) {
    const bucket = Math.min(histogram.length - 1, Math.ceil(Math.log2(milliseconds * 1000 + 1) * 64));
    histogram[bucket]++;
    sumLatency += milliseconds;
    maxLatency = Math.max(maxLatency, milliseconds);
    completed++;
}

function measured() {
    if (phase === 'failed') return;
    phase = 'measured';
    start = performance.now();
    cpuStart = process.cpuUsage();
    setTimeout(() => {
        if (phase === 'failed') return;
        elapsed = (performance.now() - start) / 1000;
        cpuEnd = process.cpuUsage(cpuStart);
        phase = 'draining';
        for (const client of clients) if (client.pending === null) close(client);
    }, duration * 1000);
}

function receive(client, chunk) {
    client.input = client.input.length === 0 ? chunk : Buffer.concat([client.input, chunk]);
    if (!client.upgraded) {
        const end = client.input.indexOf('\r\n\r\n');
        if (end === -1) {
            if (client.input.length > 65536) throw new Error('Oversized handshake.');
            return;
        }
        const header = client.input.subarray(0, end).toString('latin1');
        const accept = /^sec-websocket-accept:\s*(.+)$/im.exec(header)?.[1].trim();
        if (!header.startsWith('HTTP/1.1 101 ') || accept !== client.accept) throw new Error(`Invalid handshake: ${header}`);
        client.input = client.input.subarray(end + 4);
        client.upgraded = true;
        if (++ready === concurrency) {
            phase = 'warmup';
            for (const connection of clients) send(connection);
            setTimeout(measured, warmup * 1000);
        }
    }
    while (client.input.length >= 2) {
        const first = client.input[0];
        const second = client.input[1];
        if ((first & 0x70) !== 0 || (second & 0x80) !== 0 || (first & 0x80) === 0) throw new Error('Invalid server frame.');
        let length = second & 0x7f;
        let offset = 2;
        if (length === 126) {
            if (client.input.length < 4) return;
            length = client.input.readUInt16BE(2);
            offset = 4;
        } else if (length === 127) {
            throw new Error('Unexpected large server frame.');
        }
        if (client.input.length < offset + length) return;
        const payload = client.input.subarray(offset, offset + length);
        client.input = client.input.subarray(offset + length);
        const opcode = first & 15;
        if (opcode === 8) {
            if (!client.closing) throw new Error('Server closed during benchmark.');
            client.socket.end();
            continue;
        }
        if (opcode === 9) {
            client.socket.write(maskedFrame(10, payload));
            continue;
        }
        if (opcode !== 2 || client.pending === null || !payload.equals(client.payload)) throw new Error('Echo payload mismatch or duplicate.');
        if (phase === 'measured' && client.pending.measured) record(performance.now() - client.pending.started);
        client.pending = null;
        if (phase === 'draining') close(client);
        else send(client);
    }
}

for (let id = 0; id < concurrency; id++) {
    const key = crypto.randomBytes(16).toString('base64');
    const client = {
        id, socket: net.createConnection({ host, port }), input: Buffer.alloc(0), upgraded: false,
        accept: crypto.createHash('sha1').update(key + '258EAFA5-E914-47DA-95CA-C5AB0DC85B11').digest('base64'),
        payload: Buffer.alloc(payloadBytes, 0x41 + id % 26), sequence: 0, pending: null, closing: false,
    };
    clients.push(client);
    client.socket.setNoDelay(true);
    client.socket.on('connect', () => client.socket.write(
        `GET /echo HTTP/1.1\r\nHost: ${host}:${port}\r\nUpgrade: websocket\r\nConnection: Upgrade\r\nSec-WebSocket-Key: ${key}\r\nSec-WebSocket-Version: 13\r\n\r\n`,
    ));
    client.socket.on('data', chunk => {
        try { receive(client, chunk); } catch (error) { fail(error); }
    });
    client.socket.on('error', fail);
    client.socket.on('close', () => {
        if (phase !== 'draining' && phase !== 'failed') fail(new Error('Connection closed before drain.'));
        if (++closed === concurrency) finish();
    });
}

await finished;
clearTimeout(watchdog);
function percentile(fraction) {
    if (completed === 0) return null;
    const rank = Math.ceil(completed * fraction);
    let count = 0;
    for (let bucket = 0; bucket < histogram.length; bucket++) {
        count += histogram[bucket];
        if (count >= rank) return (2 ** (bucket / 64) - 1) / 1000;
    }
    return maxLatency;
}
console.log(JSON.stringify({
    protocol: 'websocket', concurrency, payload_bytes: payloadBytes, inflight_per_connection: 1,
    warmup_seconds: warmup, requested_seconds: duration, elapsed_seconds: elapsed,
    offered_messages: offered, completed_messages: completed, messages_per_second: elapsed ? completed / elapsed : 0,
    errors, first_error: firstError,
    latency_ms: { p50: percentile(0.5), p95: percentile(0.95), p99: percentile(0.99),
        mean: completed ? sumLatency / completed : null, max: maxLatency },
    latency_histogram: '64 log2 buckets per doubling; reported upper bound, about 1.1% resolution',
    client: { node: process.version, cpu_user_seconds: (cpuEnd?.user ?? 0) / 1e6,
        cpu_system_seconds: (cpuEnd?.system ?? 0) / 1e6, rss_bytes: process.memoryUsage().rss },
}));
process.exitCode = errors === 0 && completed > 0 ? 0 : 1;
