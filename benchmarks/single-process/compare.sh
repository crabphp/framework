#!/bin/bash
# Crab (main; JIT off and tracing JIT) against Elysia, one process each; target order rotates per round.
set -u
B=/tmp/crab-bench; ROUNDS=${1:-3}; SECS=${2:-8}
WRK="podman run --rm --network host -v $B/scripts:/scripts:ro,Z docker.io/williamyeh/wrk"
PHP="php -d opcache.enable_cli=1 -d opcache.validate_timestamps=0"
start() {
  case $1 in
    crab) cd $B/app-after; setsid $PHP -d opcache.jit=disable cua start --single --address=tcp://127.0.0.1:9102 < /dev/null > $B/server-crab.log 2>&1 & echo "$! 9102";;
    crab-jit) cd $B/app-after; setsid $PHP -d opcache.jit=tracing -d opcache.jit_buffer_size=128M cua start --single --address=tcp://127.0.0.1:9102 < /dev/null > $B/server-crab-jit.log 2>&1 & echo "$! 9102";;
    elysia) cd $B/elysia; NODE_ENV=production setsid /tmp/bun-dl/bun-linux-x64/bun server.ts < /dev/null > $B/server-elysia.log 2>&1 & echo "$! 9103";;
  esac
}
ORDERS=("crab crab-jit elysia" "crab-jit elysia crab" "elysia crab crab-jit")
for round in $(seq 1 $ROUNDS); do
  for t in ${ORDERS[$(( (round - 1) % 3 ))]}; do
    read pid port < <(start $t)
    for i in $(seq 1 100); do curl -s -o /dev/null http://127.0.0.1:$port/hello && break; sleep 0.1; done
    $WRK -t2 -c64 -d3s http://127.0.0.1:$port/hello > /dev/null 2>&1
    eps="hello sqlite mysql post"; [ $t != elysia ] && eps="hello sqlite mysql mysql-async post"
    for ep in $eps; do
      case $ep in
        hello) args="http://127.0.0.1:$port/hello";;
        sqlite) args="-s /scripts/ids.lua http://127.0.0.1:$port -- /users/";;
        mysql) args="-s /scripts/ids.lua http://127.0.0.1:$port -- /mysql/users/";;
        mysql-async) args="-s /scripts/ids.lua http://127.0.0.1:$port -- /mysql-async/users/";;
        post) args="-s /scripts/post.lua http://127.0.0.1:$port/users";;
      esac
      $WRK -t2 -c64 -d2s $args > /dev/null 2>&1
      out=$($WRK -t2 -c64 -d${SECS}s --latency $args 2>&1)
      rps=$(echo "$out" | awk '/Requests\/sec/ {print $2}')
      p50=$(echo "$out" | awk '$1=="50%" {print $2}')
      p99=$(echo "$out" | awk '$1=="99%" {print $2}')
      errs=$(echo "$out" | awk '/Non-2xx|Socket errors/ {print}' | tr '\n' ' ')
      echo "round=$round t=$t ep=$ep rps=$rps p50=$p50 p99=$p99 $errs"
    done
    kill $pid; wait $pid 2>/dev/null; sleep 0.5
  done
done
