import { Elysia, t } from 'elysia';
import { SQL } from 'bun';
import { Database } from 'bun:sqlite';
const lite = new Database('/tmp/crab-bench/app-after/storage/bench.sqlite', { readonly: true });
const byId = lite.query('SELECT id, name, email, age FROM users WHERE id = ? LIMIT 1');
const sql = new SQL({ adapter: 'mysql', hostname: '127.0.0.1', port: 23306, database: 'bench', username: 'root', password: '',
    max: 10, idleTimeout: 0, maxLifetime: 0, connectionTimeout: 10 });
new Elysia()
    .get('/hello', () => ({ message: 'Hello, world' }))
    .get('/users/:id', ({ params, status }) => byId.get(Number(params.id)) ?? status(404, { error: 'Not found' }))
    .get('/mysql/users/:id', async ({ params, status }) => {
        const rows = await sql`SELECT id, name, email, age FROM users WHERE id = ${Number(params.id)} LIMIT 1`;
        return rows[0] ?? status(404, { error: 'Not found' });
    })
    .post('/users', ({ body, status }) => status(201, body), {
        parse: async ({ request }) => JSON.parse(await request.text(), (key, value, context) => {
            if (key === 'age' && typeof value === 'number' && !/^-?\d+$/.test(context.source)) return null;
            return value;
        }),
        body: t.Object({
            name: t.String({ minLength: 1, maxLength: 100 }),
            email: t.String({ format: 'email' }),
            age: t.Number({ minimum: 18, maximum: 120, multipleOf: 1 }),
        }),
    })
    .listen({ hostname: '127.0.0.1', port: 9103 });
