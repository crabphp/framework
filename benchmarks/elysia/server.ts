import { Elysia } from 'elysia';

const options = Object.fromEntries(process.argv.slice(2).map(argument => {
  const separator = argument.indexOf('=');
  if (!argument.startsWith('--') || separator < 0) {
    throw new Error(`Invalid option: ${argument}`);
  }
  return [argument.slice(2, separator), argument.slice(separator + 1)];
}));
const protocol = options.protocol ?? 'http';
const port = Number(options.port ?? 8080);
if (!['http', 'ws'].includes(protocol) || !Number.isInteger(port) || port < 1 || port > 65535) {
  throw new Error('Usage: bun server.ts --protocol=http|ws --port=8080');
}

const app = new Elysia({
  websocket: { perMessageDeflate: false, maxPayloadLength: 1048576 },
});
if (protocol === 'http') {
  app.headers({ 'content-type': 'text/plain; charset=utf-8' });
  app.get('/', () => 'Hello from Crab\n');
} else {
  app.ws('/echo', {
    message(ws, message) {
      if (typeof message === 'string') {
        ws.sendText(message);
      } else if (Buffer.isBuffer(message)) {
        ws.sendBinary(message);
      } else {
        ws.close(1003, 'Expected binary or text data');
      }
    },
  });
}
app.listen({ hostname: '127.0.0.1', port, idleTimeout: 30, development: false });

process.once('SIGTERM', async () => {
  await app.stop();
  process.exit(0);
});
