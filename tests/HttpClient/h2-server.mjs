// An HTTP/2 server for the HTTP client's tests: node h2-server.mjs <pem file holding a certificate and its key>.
// It prints the port it listens on, then serves until it is killed.
import http2 from 'node:http2';
import fs from 'node:fs';
import zlib from 'node:zlib';
import crypto from 'node:crypto';

const pem = fs.readFileSync(process.argv[2]);
const server = http2.createSecureServer({ key: pem, cert: pem, settings: { maxConcurrentStreams: 3 } });
const { NGHTTP2_REFUSED_STREAM, NGHTTP2_INTERNAL_ERROR } = http2.constants;

let sessions = 0;
const seen = {};
const received = {};
let batch = [];
let batchNumber = 0;
server.on('session', (session) => {
    session.number = ++sessions;
});
server.on('stream', (stream, headers) => {
    const session = stream.session.number;
    const path = headers[':path'].split('?')[0];
    seen[path] = (seen[path] ?? 0) + 1;
    const chunks = [];
    stream.on('error', () => {});
    stream.on('data', (chunk) => {
        chunks.push(chunk);
        received[path] = (received[path] ?? 0) + chunk.length;
    });
    const reply = (status, body = '', extra = {}) => {
        if (stream.destroyed || stream.closed) return;
        stream.respond({ ':status': status, ...extra });
        stream.end(body);
    };
    const echo = () => {
        const body = Buffer.concat(chunks);
        return JSON.stringify({
            method: headers[':method'],
            path: headers[':path'],
            authority: headers[':authority'],
            scheme: headers[':scheme'],
            headers,
            length: body.length,
            hash: crypto.createHash('md5').update(body).digest('hex'),
            body: body.length < 2000 ? body.toString() : '',
            session,
            times: seen[path],
        });
    };
    switch (path) {
        case '/seen':
            reply(200, JSON.stringify({ requests: seen, bytes: received }), { 'content-type': 'application/json' });
            break;
        case '/slow':
            setTimeout(() => { if (!stream.destroyed) reply(200, echo(), { 'content-type': 'application/json' }); }, 200);
            break;
        case '/batch':
            batch.push({ reply, echo });
            if (batch.length === 3) {
                const ready = batch;
                const number = ++batchNumber;
                batch = [];
                setImmediate(() => {
                    for (const pending of ready) {
                        pending.reply(200, JSON.stringify({ ...JSON.parse(pending.echo()), batch: number }), { 'content-type': 'application/json' });
                    }
                });
            }
            break;
        case '/big': {
            stream.respond({ ':status': 200, 'content-type': 'application/octet-stream' });
            const block = Buffer.alloc(65536, 'abcdefghijklmnopqrstuvwxyz');
            let left = 80;
            const more = () => {
                while (left > 0) {
                    left--;
                    if (!stream.write(block)) { stream.once('drain', more); return; }
                }
                stream.end();
            };
            more();
            break;
        }
        case '/gzip':
            reply(200, zlib.gzipSync('{"compressed":true}'), { 'content-encoding': 'gzip', 'content-type': 'application/json' });
            break;
        case '/long-headers':
            reply(200, 'ok', { 'x-long': 'h'.repeat(40000) });
            break;
        case '/nothing':
            stream.respond({ ':status': 204 }, { endStream: true });
            break;
        case '/trailers':
            stream.respond({ ':status': 200 }, { waitForTrailers: true });
            stream.on('wantTrailers', () => stream.sendTrailers({ 'x-checksum': 'abc' }));
            stream.end('with trailers');
            break;
        case '/redirect':
            reply(302, '', { location: '/echo?redirected=1' });
            break;
        case '/refuse-once':
            if (seen[path] === 1) { stream.close(NGHTTP2_REFUSED_STREAM); break; }
            stream.on('end', () => reply(200, echo(), { 'content-type': 'application/json' }));
            stream.resume();
            break;
        case '/reset':
            stream.close(NGHTTP2_INTERNAL_ERROR);
            break;
        case '/goaway':
            reply(200, 'last one here');
            stream.session.goaway();
            break;
        case '/busy-twice':
            if (seen[path] <= 2) { reply(503, 'busy', { 'retry-after': '0' }); break; }
            reply(200, `after ${seen[path]} tries`);
            break;
        case '/early':
            // Answers without reading the body it is being sent.
            reply(413, 'too large');
            break;
        default:
            stream.on('end', () => reply(headers[':method'] === 'HEAD' ? 200 : 200, headers[':method'] === 'HEAD' ? '' : echo(), { 'content-type': 'application/json', 'set-cookie': 'h2=yes; Path=/' }));
            stream.resume();
    }
});
server.listen(0, '127.0.0.1', () => console.log(server.address().port));
