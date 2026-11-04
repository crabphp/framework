#!/bin/bash
# Builds /tmp/crab-bench: the app on a release (before) and on this checkout (after), SQLite and a
# disposable MySQL 8.4 on 127.0.0.1:23306, both with the same 10,000 users, and Elysia on Bun 1.4.2.
# Usage: setup.sh [release tag, default v0.4.0]. Needs php, composer, podman and curl.
set -eu
TAG=${1:-v0.4.0}
HERE=$(cd "$(dirname "$0")" && pwd); FW=$(cd "$HERE/../.." && pwd); B=/tmp/crab-bench
rm -rf $B; mkdir -p $B
git -C $FW worktree add -q --detach $B/fw-before $TAG; ln -s $FW $B/fw-after
cp -r $HERE/scripts $HERE/run.sh $HERE/compare.sh $B/
for v in before after; do
  A=$B/app-$v; mkdir -p $A/config $A/storage/logs; cp -r $HERE/app/. $A/; cp $FW/../crab/cua $A/cua
  printf '%s\n' '<?php' '' 'declare(strict_types=1);' '' 'use Crab\App;' '' 'return App::configure(__DIR__)' "    ->withRouting(routes: 'routes/web.php')" '    ->create();' > $A/app.php
  cat > $A/config/database.php <<PHP
<?php
\$mysql = ['driver' => 'mysql', 'host' => '127.0.0.1', 'port' => 23306, 'database' => 'bench', 'username' => 'root', 'password' => '', 'pool' => 10];
return ['default' => 'main', 'connections' => [
    'main' => ['driver' => 'sqlite', 'database' => '$A/storage/bench.sqlite'],
    'mysql' => \$mysql,
    'mysql_async' => [...\$mysql, 'async' => true],
]];
PHP
  echo "<?php return ['debug' => false];" > $A/config/app.php
  echo "<?php return ['path' => '$A/storage/logs/app.log', 'level' => 'error'];" > $A/config/log.php
  cat > $A/composer.json <<JSON
{"name":"bench/$v","type":"project","require":{"php":"^8.4","crabphp/framework":"@dev"},"repositories":[{"type":"path","url":"$B/fw-$v","options":{"symlink":true}}],"autoload":{"psr-4":{"App\\\\":"app/"}},"minimum-stability":"dev","prefer-stable":true}
JSON
  (cd $A && composer install -q --no-dev --classmap-authoritative)
  php -r '$db=new PDO("sqlite:'$A'/storage/bench.sqlite"); $db->exec("create table users (id integer primary key, name text, email text, age integer)"); $db->beginTransaction(); $s=$db->prepare("insert into users (name,email,age) values (?,?,?)"); for($i=1;$i<=10000;$i++) $s->execute(["User $i","u$i@example.com",18+$i%80]); $db->commit();'
done
podman rm -f crab-bench-mysql >/dev/null 2>&1 || true
podman run -d --name crab-bench-mysql -p 127.0.0.1:23306:3306 -e MYSQL_ALLOW_EMPTY_PASSWORD=yes -e MYSQL_ROOT_HOST=% \
  -e MYSQL_DATABASE=bench --tmpfs /var/lib/mysql:rw,size=1g docker.io/library/mysql:8.4 >/dev/null
for i in $(seq 1 90); do podman exec crab-bench-mysql mysql -uroot -e 'select 1' bench >/dev/null 2>&1 && break; sleep 1; done
podman exec -i crab-bench-mysql mysql -uroot bench <<'SQL'
create table users (id int primary key auto_increment, name varchar(100), email varchar(100), age int);
set session cte_max_recursion_depth = 20000;
insert into users (name, email, age)
with recursive n(i) as (select 1 union all select i + 1 from n where i < 10000)
select concat('User ', i), concat('u', i, '@example.com'), 18 + i % 80 from n;
SQL
# Bun 1.4.2, checked against the release's checksums, and the pinned Elysia.
D=/tmp/bun-dl
if [ ! -x $D/bun-linux-x64/bun ]; then
  rm -rf $D; mkdir -p $D
  curl -fsSL -o $D/bun-linux-x64.zip https://github.com/oven-sh/bun/releases/download/bun-v1.4.2/bun-linux-x64.zip
  curl -fsSL -o $D/SHASUMS256.txt https://github.com/oven-sh/bun/releases/download/bun-v1.4.2/SHASUMS256.txt
  (cd $D && grep ' bun-linux-x64.zip$' SHASUMS256.txt | sha256sum -c - && unzip -q bun-linux-x64.zip)
fi
mkdir -p $B/elysia; cp $HERE/elysia/* $B/elysia/; (cd $B/elysia && $D/bun-linux-x64/bun install --frozen-lockfile >/dev/null)
echo "Ready: $B/run.sh [rounds] [seconds] for $TAG against this checkout; $B/compare.sh [rounds] [seconds] for Crab against Elysia."
echo "Afterwards: podman rm -f crab-bench-mysql; git -C $FW worktree remove --force $B/fw-before"
