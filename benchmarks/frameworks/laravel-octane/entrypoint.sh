#!/bin/sh
set -eu
cd /srv/app
exec php artisan octane:frankenphp --host=0.0.0.0 --port=8080 --workers=2 --max-requests=500 --log-level=error
