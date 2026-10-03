const options = Object.fromEntries(process.argv.slice(2).map(argument => {
  const separator = argument.indexOf('=');
  if (!argument.startsWith('--') || separator < 0) {
    throw new Error(`Invalid option: ${argument}`);
  }
  return [argument.slice(2, separator), argument.slice(separator + 1)];
}));
const protocol = options.protocol ?? 'http';
const port = Number(options.port ?? 8080);
if (protocol !== 'http' || !Number.isInteger(port) || port < 1 || port > 65535) {
  throw new Error('Usage: bun bun.ts --protocol=http --port=8080');
}

const server = Bun.serve({
  hostname: '127.0.0.1',
  port,
  development: false,
  idleTimeout: 30,
  routes: {
    '/': {
      GET: () => new Response('Hello from Crab\n', {
        headers: { 'content-type': 'text/plain; charset=utf-8' },
      }),
    },
  },
});

process.once('SIGTERM', async () => {
  await server.stop();
  process.exit(0);
});
