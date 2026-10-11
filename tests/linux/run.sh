#!/bin/sh
# Manual only: builds a Linux image from the working tree and runs the test
# suite inside it. Needs Docker. Extra arguments replace the default command,
# for example: tests/linux/run.sh vendor/bin/phpunit --filter HttpTest
# Use tests/linux/run.sh --mysql to run both suites against an isolated MySQL
# 8.4 server, including verified TLS. No host ports are published. Containers,
# their volumes, and the crab-linux network are removed on failure or success.
set -eu
cd "$(dirname "$0")/../.."
mysql=false
if [ "${1:-}" = --mysql ]; then
    mysql=true
    shift
fi
suite=crab-linux-suite-$$
certificate=crab-linux-certificate-$$
mysql_created=false
network_created=false
certificates=
cleanup() {
    docker rm --force --volumes "$suite" "$certificate" >/dev/null 2>&1 || :
    if [ "$mysql_created" = true ]; then
        docker rm --force --volumes crab-linux-mysql >/dev/null 2>&1 || :
    fi
    if [ "$network_created" = true ]; then
        docker network rm crab-linux >/dev/null 2>&1 || :
    fi
    if [ -n "$certificates" ]; then
        rm -rf "$certificates"
    fi
}
trap cleanup EXIT
trap 'exit 130' INT
trap 'exit 143' TERM HUP
# Do not remove a resource left by somebody else's run.
for name in "$suite" "$certificate"; do
    if docker container inspect "$name" >/dev/null 2>&1; then
        echo "Container $name already exists; wait for its owner to finish." >&2
        trap - EXIT
        exit 1
    fi
done
docker build --quiet --file tests/linux/Dockerfile --tag crab-engine-linux-test . >/dev/null
if [ "$mysql" = false ]; then
    docker run --rm --init --name "$suite" crab-engine-linux-test "$@"
    exit
fi
if docker container inspect crab-linux-mysql >/dev/null 2>&1 || docker network inspect crab-linux >/dev/null 2>&1; then
    echo 'crab-linux-mysql or crab-linux already exists; wait for its owner to finish.' >&2
    exit 1
fi
docker network create crab-linux >/dev/null
network_created=true
docker run --detach --name crab-linux-mysql --network crab-linux \
    --env MYSQL_ROOT_HOST=% --env MYSQL_ALLOW_EMPTY_PASSWORD=yes \
    --env MYSQL_DATABASE=crab_test mysql:8.4 >/dev/null
mysql_created=true
attempt=0
until docker exec crab-linux-mysql \
    mysql --protocol=tcp --host=127.0.0.1 --user=root --execute='SELECT 1' >/dev/null 2>&1; do
    attempt=$((attempt + 1))
    if [ "$attempt" -ge 90 ]; then
        docker logs crab-linux-mysql >&2
        echo 'MySQL did not accept connections within 90 seconds.' >&2
        exit 1
    fi
    sleep 1
done
# Use this disposable server's actual CA and certificate name, keeping peer
# verification enabled without trusting its generated certificate globally.
certificates=$(mktemp -d "${TMPDIR:-/tmp}/crab-linux-certificates.XXXXXX")
chmod 755 "$certificates"
docker cp crab-linux-mysql:/var/lib/mysql/ca.pem "$certificates/ca.pem" >/dev/null
docker cp crab-linux-mysql:/var/lib/mysql/server-cert.pem "$certificates/server.pem" >/dev/null
chmod 644 "$certificates"/*.pem
peer=$(docker run --rm --name "$certificate" --volume "$certificates:/crab-mysql:ro" \
    crab-engine-linux-test php -r 'echo openssl_x509_parse(file_get_contents("/crab-mysql/server.pem"))["subject"]["CN"];')
docker run --rm --init --name "$suite" --network crab-linux \
    --volume "$certificates:/crab-mysql:ro" \
    --env 'CRAB_TEST_MYSQL_DSN=mysql:host=crab-linux-mysql;port=3306;dbname=crab_test;charset=utf8mb4' \
    --env CRAB_TEST_MYSQL_USER=root --env CRAB_TEST_MYSQL_PASSWORD= \
    --env CRAB_TEST_MYSQL_CA=/crab-mysql/ca.pem --env "CRAB_TEST_MYSQL_TLS_PEER_NAME=$peer" \
    crab-engine-linux-test "$@"
