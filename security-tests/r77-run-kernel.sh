#!/usr/bin/env bash
set -euo pipefail
root=/opt/gst-rc1-staging/s98-r62-fleetops-v2
script="${1:-r77-http-kernel-cases.php}"
case "$script" in
  r77-http-kernel-cases.php|r77-http-preflight.php) ;;
  *) echo "Disallowed script" >&2; exit 2 ;;
esac
timeout 45 docker run --rm --network none --read-only --memory 650m --cpus 1 --pids-limit 100   --tmpfs /tmp:rw,nosuid,size=32m   --tmpfs /fleetbase/api/bootstrap/cache:rw,nosuid,size=8m   --tmpfs /fleetbase/api/storage:rw,nosuid,size=32m   --mount type=bind,src="$root",dst=/lab/fork,readonly   -e APP_ENV=testing -e APP_DEBUG=false   -e APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=   -e DB_CONNECTION=mysql -e DB_HOST=127.0.0.1   -e DB_DATABASE=synthetic_r77 -e DB_USERNAME=synthetic   -e DB_PASSWORD=synthetic   --entrypoint sh gst-rc1-api:8e01d0b-r50-lab   -c 'mkdir -p /fleetbase/api/storage/framework/views /fleetbase/api/storage/framework/cache/data /fleetbase/api/storage/framework/sessions /fleetbase/api/storage/logs; cd /fleetbase/api; php "/lab/fork/security-tests/$1"' _ "$script"
