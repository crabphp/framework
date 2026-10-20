#!/bin/sh
set -eu
export APP_ENV=prod SCAN_CACHEABLE=true
exec php bin/hyperf.php start
