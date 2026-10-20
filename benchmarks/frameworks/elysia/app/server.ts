import { Elysia, t } from 'elysia';
import { SQL } from 'bun';
const sql = new SQL({ adapter: 'mysql', hostname: 'mysql', port: 23306,
    database: 'benchmark', username: 'bench', password: 'bench', allowPublicKeyRetrieval: true,
    max: 10, idleTimeout: 0, maxLifetime: 0, connectionTimeout: 10 });
new Elysia()
    .get('/hello', () => ({ message: 'Hello, world' }))
    .get('/users/:id', async ({ params, status }) => {
        const rows = await sql`SELECT id, name, email, age FROM users WHERE id = ${Number(params.id)} LIMIT 1`;
        return rows[0] ?? status(404, { error: 'Not found' });
    })
    .get('/__bench', () => ({ bun: Bun.version, elysia: '1.4.30', debug: false,
        production: process.env.NODE_ENV === 'production', pid: process.pid,
        workers: Number(process.env.BENCH_WORKERS), pool_per_worker: 10, reuse_port: true }))
    .post('/users', ({ body, status }) => status(201, body), {
        parse: async ({ request }) => JSON.parse(await request.text(), (key, value, context) => {
            // PHP distinguishes 36 from 36.0. Preserve that distinction before schema validation.
            if (key === 'age' && typeof value === 'number' && !/^-?\d+$/.test(context.source)) return null;
            return value;
        }),
        body: t.Object({
            name: t.String({ minLength: 1, maxLength: 100 }),
            email: t.String({ format: 'email' }),
            age: t.Number({ minimum: 18, maximum: 120, multipleOf: 1 }),
        }),
    })
    .listen({ hostname: '0.0.0.0', port: 8080, reusePort: true });
