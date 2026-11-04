#!/bin/bash
# Alternates before (v0.4.0) and after (main) each round: one process, OPcache on, JIT off unless JIT=1.
# Usage: run.sh [rounds] [seconds] [endpoints...]; ORDER="after before" runs main first in each round.
set -u
B=/tmp/crab-bench
ROUNDS=${1:-3}; SECS=${2:-8}; shift 2 2>/dev/null || true
EPS=${*:-hello sqlite mysql mysql-async post}
JITFLAG=$([ "${JIT:-0}" = 1 ] && echo "-d opcache.jit=tracing -d opcache.jit_buffer_size=128M" || echo "-d opcache.jit=disable")
PHP="php -d opcache.enable_cli=1 $JITFLAG -d opcache.validate_timestamps=0"
WRK="podman run --rm --network host -v $B/scripts:/scripts:ro,Z docker.io/williamyeh/wrk"
start() { cd $B/app-$1; setsid $PHP cua start --single --address=tcp://127.0.0.1:$2 < /dev/null > $B/server-$1.log 2>&1 & echo $!; }
for round in $(seq 1 $ROUNDS); do
  for v in ${ORDER:-before after}; do
    port=$([ $v = before ] && echo 9101 || echo 9102)
    pid=$(start $v $port)
    for i in $(seq 1 100); do curl -s -o /dev/null http://127.0.0.1:$port/hello && break; sleep 0.1; done
    s=""; for p in /hello /users/7 /mysql/users/7 /mysql-async/users/7; do s="$s$(curl -s -o /dev/null -w '%{http_code}' http://127.0.0.1:$port$p)"; done
    s="$s$(curl -s -o /dev/null -w '%{http_code}' -X POST -H 'Content-Type: application/json' -d '{"name":"Ada","email":"ada@example.com","age":36}' http://127.0.0.1:$port/users)"
    [ "$s" = "200200200200201" ] || echo "SANITY $v $s"
    $WRK -t2 -c64 -d3s http://127.0.0.1:$port/hello > /dev/null 2>&1
    for ep in $EPS; do
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
      p99=$(echo "$out" | awk '$1=="99%" {print $2}')
      errs=$(echo "$out" | awk '/Non-2xx|Socket errors/ {print}' | tr '\n' ' ')
      echo "round=$round v=$v ep=$ep rps=$rps p99=$p99 $errs"
    done
    kill $pid; wait $pid 2>/dev/null
  done
done
