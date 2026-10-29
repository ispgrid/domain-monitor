#!/usr/bin/env bash
# Startup script for the Docker image: prepares the /data volume, writes .env from the
# container environment, starts the scheduler and then runs Apache.
set -euo pipefail

APP=/var/www/html
DATA=/data
DEFAULTS=/usr/src/domain-monitor
STARTUP_LOG="$DATA/logs/container-startup.log"

mkdir -p "$DATA/logs" "$DATA/cache" "$DATA/uploads"

log() {
  local level="$1"; shift
  local line
  line="[$(date '+%Y-%m-%d %H:%M:%S')] [$level] $*"
  echo "$line"
  echo "$line" >> "$STARTUP_LOG"
}

is_true() {
  case "${1,,}" in 1|true|yes|on) return 0 ;; *) return 1 ;; esac
}

# Double-quote a value for .env: \ " and $ are escaped, everything else is literal
dotenv_quote() {
  local s="$1" out="" c i
  for (( i=0; i<${#s}; i++ )); do
    c="${s:i:1}"
    # shellcheck disable=SC1003
    case "$c" in
      '\'|'"'|'$') out+="\\$c" ;;
      *) out+="$c" ;;
    esac
  done
  printf '"%s"' "$out"
}

# Set KEY=value in .env, replacing an existing line. awk reads the line from the
# environment, so no character in the value is treated specially.
set_env() {
  local key="$1" line tmp
  line="${key}=$(dotenv_quote "$2")"
  tmp="$(mktemp)"
  KV_KEY="$key" KV_LINE="$line" awk '
    BEGIN { k = ENVIRON["KV_KEY"] "="; l = ENVIRON["KV_LINE"] }
    index($0, k) == 1 { if (!done) print l; done = 1; next }
    { print }
    END { if (!done) print l }
  ' "$DATA/.env" > "$tmp"
  cat "$tmp" > "$DATA/.env"
  rm -f "$tmp"
}

VERSION="$(sed -n "s/.*getValue('app_version', '\([0-9.]*\)').*/\1/p" "$APP/app/Models/Setting.php" | head -n1)"
log INFO "Starting Domain Monitor ${VERSION:-unknown}"

# --- .env -----------------------------------------------------------------
if [[ ! -f "$DATA/.env" ]]; then
  cp "$APP/env.example.txt" "$DATA/.env"
  set_env APP_ENV "${APP_ENV:-production}"
  log INFO "Created .env in /data"
fi

# Values set on the container win over .env, so changing them in docker-compose.yml works.
# APP_ENCRYPTION_KEY is only written when you set it: the installer creates it otherwise.
written=()
for key in APP_ENV APP_ENCRYPTION_KEY DB_HOST DB_PORT DB_DATABASE DB_USERNAME DB_PASSWORD SESSION_LIFETIME; do
  if [[ -n "${!key:-}" ]]; then
    set_env "$key" "${!key}"
    written+=("$key")
  fi
done
set_env DOCKER true
log INFO "Container settings written to .env: ${written[*]:-none}"

# --- Uploads, cache -------------------------------------------------------
# The shipped .htaccess only allows images to be served from uploads/
cp -f "$DEFAULTS/uploads/.htaccess" "$DATA/uploads/.htaccess"
mkdir -p "$DATA/uploads/avatars"

# Compiled templates belong to the old image after an upgrade
rm -rf "$DATA/cache/twig"

# --- Apache options -------------------------------------------------------
rm -f /etc/apache2/domain-monitor/*.conf
if is_true "${ACCESS_LOG_FILE:-false}"; then
  echo "CustomLog $DATA/logs/container-apache-access.log combined" > /etc/apache2/domain-monitor/access-log.conf
  log INFO "Access log is also written to logs/container-apache-access.log"
fi
if [[ -n "${TRUSTED_PROXIES:-}" ]]; then
  {
    echo "RemoteIPHeader X-Forwarded-For"
    for proxy in ${TRUSTED_PROXIES//,/ }; do
      echo "RemoteIPTrustedProxy $proxy"
    done
    echo 'SetEnvIf X-Forwarded-Proto "^https$" HTTPS=on'
  } > /etc/apache2/domain-monitor/proxy.conf
  log INFO "Trusting X-Forwarded-For and X-Forwarded-Proto from: ${TRUSTED_PROXIES}"
fi

# --- Permissions ----------------------------------------------------------
touch "$DATA/logs/container-apache-error.log"
chown -R www-data:www-data "$DATA"
chmod 640 "$DATA/.env"

# --- Database -------------------------------------------------------------
db_ok=false
for _ in $(seq 1 30); do
  if reason="$(runuser -u www-data -- php "$APP/docker/db-check.php" 2>&1)"; then
    db_ok=true
    break
  fi
  sleep 2
done
if $db_ok; then
  log INFO "Database is reachable"
else
  log ERROR "Database is not reachable: ${reason}"
fi

if [[ ! -f "$DATA/.installed" ]]; then
  log INFO "Not installed yet: open the app in your browser to run the installer"
fi

# --- Background jobs ------------------------------------------------------
# Apache errors also go to docker logs
tail -n0 -F "$DATA/logs/container-apache-error.log" >&2 &

if is_true "${SCHEDULER:-true}"; then
  (
    while true; do
      runuser -u www-data -- php "$APP/cron/scheduler.php" || true
      sleep 900
    done
  ) &
  log INFO "Scheduler started: checks are started every 15 minutes when due"
else
  log WARNING "Scheduler is off (SCHEDULER=false): run cron/scheduler.php from somewhere else"
fi

exec "$@"
