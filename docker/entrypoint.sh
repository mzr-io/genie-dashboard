#!/usr/bin/env bash
# Starts one process role, chosen by the container command.
set -euo pipefail

role="${1:-web}"
shift || true

case "$role" in
  web)
    # php-fpm and nginx run together; if either dies the container exits.
    php-fpm -F &
    fpm=$!
    nginx -e /dev/stderr -g 'daemon off;' &
    web=$!
    stopping=0
    trap 'stopping=1; kill "$fpm" "$web" 2>/dev/null || true' TERM INT
    wait -n "$fpm" "$web" || true
    if [ "$stopping" = 1 ]; then
      wait || true
      exit 0
    fi
    echo "web: php-fpm or nginx exited, stopping" >&2
    kill "$fpm" "$web" 2>/dev/null || true
    wait || true
    exit 1
    ;;
  realtime)
    exec php artisan reverb:start --host="${REVERB_SERVER_HOST:-0.0.0.0}" --port="${REVERB_SERVER_PORT:-8081}"
    ;;
  scheduler)
    # Run schedule:run, then sleep to the start of the next minute.
    trap 'exit 0' TERM INT
    while true; do
      php artisan schedule:run --no-interaction || echo "scheduler: schedule:run failed" >&2
      sleep $((60 - 10#$(date +%S))) &
      wait $!
    done
    ;;
  worker-connector|worker-compute)
    case "$role" in
      worker-connector) export HORIZON_ENV=connector ;;
      *) export HORIZON_ENV=compute ;;
    esac
    exec php artisan horizon
    ;;
  migrate)
    exec php artisan migrate --force
    ;;
  *)
    exec "$role" "$@"
    ;;
esac
