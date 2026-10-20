const workers = Array.from({ length: Number(process.env.BENCH_WORKERS ?? 2) }, () =>
    Bun.spawn([process.execPath, "server.ts"], { stdout: "inherit", stderr: "inherit", env: process.env }));
for (const signal of ["SIGINT", "SIGTERM"] as const) {
    process.on(signal, () => workers.forEach(worker => worker.kill(signal)));
}
const codes = await Promise.all(workers.map(worker => worker.exited));
process.exit(codes.find(code => code !== 0) ?? 0);
