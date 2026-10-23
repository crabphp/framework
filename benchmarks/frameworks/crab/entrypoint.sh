#!/bin/sh
set -eu
cd /srv/crab
if [ "${BENCH_JIT:-0}" = 1 ]; then
    exec php -d opcache.jit=tracing -d opcache.jit_buffer_size=128M cua start --address=tcp://0.0.0.0:8080
fi
exec php -d opcache.jit=disable -d opcache.jit_buffer_size=0 cua start --address=tcp://0.0.0.0:8080
