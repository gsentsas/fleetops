#!/usr/bin/env bash
set -euo pipefail
root=/opt/gst-rc1-staging/s98-r62-fleetops-v2
script="${1:-r77-http-kernel-cases.php}"
case "$script" in
  r77-http-kernel-cases.php|r77-http-preflight.php|r77-http-throttle.php|r77-http-v2-invalid.php|r77-http-cors-xff.php|r77-http-cors-reversed.php|r77-http-throttle.php) ;;
  *) echo "Disallowed script" >&2; exit 2 ;;
esac
timeout 45 docker run --rm --network gst-r77-internal --read-only --memory 650m --cpus 1 --pids-limit 100   --tmpfs /tmp:rw,nosuid,size=32m   --tmpfs /fleetbase/api/bootstrap/cache:rw,nosuid,size=8m   --tmpfs /fleetbase/api/storage:rw,nosuid,size=32m   --mount type=bind,src="$root",dst=/lab/fork,readonly   -e CACHE_STORE=array -e CACHE_DRIVER=array -e SESSION_DRIVER=array -e QUEUE_CONNECTION=sync -e APP_ENV=testing -e APP_DEBUG=false   -e APP_KEY=base64:AAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAAA=   -e DB_CONNECTION=mysql -e DB_HOST=gst-r77-mysql   -e DB_DATABASE=synthetic_r77 -e DB_USERNAME=root   -e DB_PASSWORD=   --entrypoint sh gst-rc1-api:8e01d0b-r50-lab   -c 'mkdir -p /fleetbase/api/storage/framework/views /fleetbase/api/storage/framework/cache/data /fleetbase/api/storage/framework/sessions /fleetbase/api/storage/logs; cd /fleetbase/api; php "/lab/fork/security-tests/$1"' _ "$script"
