ARG BASE=oven/bun:1.4.2
FROM ${BASE}
WORKDIR /srv/app
COPY elysia/package.json elysia/bun.lock ./
RUN bun install --frozen-lockfile --production
COPY elysia/app/ ./
ENV NODE_ENV=production BENCH_WORKERS=2
ENTRYPOINT ["bun", "cluster.ts"]
