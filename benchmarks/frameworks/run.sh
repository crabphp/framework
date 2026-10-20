#!/bin/bash
set -euo pipefail
cd "$(dirname "$0")"
export DOCKER_CONTEXT=orbstack
mkdir -p /tmp/benchmark-frameworks
export BENCH_SCRATCH="${BENCH_SCRATCH:-$(mktemp -d /tmp/benchmark-frameworks/run.XXXXXX)}"
export BENCH_CONTEXT="$BENCH_SCRATCH/context"
export COMPOSE_PROJECT_NAME="${COMPOSE_PROJECT_NAME:-crab-fw-bench-$(date -u +%Y%m%d%H%M%S)-$$}"
export BENCH_RESULTS="${BENCH_RESULTS:-$PWD/results/$(date -u +%Y%m%dT%H%M%SZ)}"
mkdir -p "$BENCH_SCRATCH" "$BENCH_RESULTS"
set -a
source images.env
set +a
if command -v caffeinate >/dev/null 2>&1; then caffeinate -i -w $$ & fi
started_orb=0
runner_pid=
if ! docker info >/dev/null 2>&1; then
    open -a OrbStack
    started_orb=1
    for i in {1..60}; do docker info >/dev/null 2>&1 && break; sleep 1; done
fi
cleanup() {
    code=$?
    trap - EXIT INT TERM
    if [ -n "$runner_pid" ]; then kill -TERM "$runner_pid" 2>/dev/null || true; fi
    docker compose logs --no-color > "$BENCH_RESULTS/final-server.log" 2>&1 || true
    docker compose down --volumes --remove-orphans > "$BENCH_RESULTS/cleanup.log" 2>&1 || true
    python3 -I cleanup.py >> "$BENCH_RESULTS/cleanup.log" 2>&1 || true
    if [ "$started_orb" = 1 ] && [ -z "$(docker ps -q)" ]; then orbctl stop >/dev/null 2>&1 || true; fi
    exit "$code"
}
trap cleanup EXIT
trap 'exit 130' INT TERM
python3 -I prepare.py
cp "$BENCH_SCRATCH/source-manifest.json" "$BENCH_RESULTS/"
docker compose config > "$BENCH_RESULTS/compose-resolved.yaml"
docker compose build > "$BENCH_RESULTS/build.log" 2>&1
python3 -I runner.py &
runner_pid=$!
wait "$runner_pid"
