#!/bin/sh
# Renders /run/redis/redis.conf from the baked base config and the
# environment, then execs redis-server. Secrets are read from *_FILE paths
# (Container Apps secret volumes) or the plain variables; passwords are
# written to the config only as SHA-256 hashes.
#
#   REDIS_PORT (6379)  REDIS_MAXMEMORY (256mb)  REDIS_MAXMEMORY_POLICY (allkeys-lru)
#   REDIS_TLS=1 + REDIS_TLS_CERT_FILE, REDIS_TLS_KEY_FILE, REDIS_TLS_CA_FILE,
#       REDIS_TLS_PORT (6380): TLS only (plain port closed)
#   REDIS_ACL_FILE      use this ACL file as is (all users below are ignored)
#   REDIS_ADMIN_PASSWORD[_FILE]  "admin" user: everything (operators only)
#   REDIS_PASSWORD[_FILE]        single-site mode: the "default" user, all keys,
#                                dangerous commands denied
#   REDIS_APP_USERS="sitea,siteb" shared mode: one user per site, keys limited
#       to "<name>:*" (set REDIS_PREFIX=<name> in that Drupal site), password
#       from REDIS_APP_PASSWORD_<NAME>[_FILE] (NAME upper-cased)
set -eu

conf=/run/redis/redis.conf
secret() { # secret VAR -> value of $VAR_FILE contents or $VAR
  eval "f=\${${1}_FILE:-}"; eval "v=\${${1}:-}"
  if [ -n "$f" ]; then tr -d '\r\n' < "$f"; else printf '%s' "$v"; fi
}
hash() { printf '%s' "$1" | sha256sum | cut -d' ' -f1; }
die() { echo "perf-redis-entrypoint: $*" >&2; exit 1; }

# App users may run everything except commands that affect other tenants or
# the server: no FLUSHALL/FLUSHDB, CONFIG, DEBUG, KEYS, SHUTDOWN, MONITOR, ACL,
# MODULE, SWAPDB, MIGRATE... (@dangerous) and no cross-DB SELECT. INFO is
# allowed back for the Drupal redis report page. SCAN is denied: ACL key
# patterns do not filter SCAN results, so it would list other sites' key
# names (the redis module only uses SCAN on its report page).
APP_CMDS='+@all -@dangerous +info -select -scan'

cat /etc/perf-redis/redis.base.conf > "$conf"
{
  echo "maxmemory ${REDIS_MAXMEMORY}"
  echo "maxmemory-policy ${REDIS_MAXMEMORY_POLICY}"
  if [ "${REDIS_TLS}" = "1" ]; then
    [ -r "${REDIS_TLS_CERT_FILE:-}" ] && [ -r "${REDIS_TLS_KEY_FILE:-}" ] || die "REDIS_TLS=1 needs readable REDIS_TLS_CERT_FILE and REDIS_TLS_KEY_FILE"
    echo "port 0"
    echo "tls-port ${REDIS_TLS_PORT:-6380}"
    echo "tls-cert-file ${REDIS_TLS_CERT_FILE}"
    echo "tls-key-file ${REDIS_TLS_KEY_FILE}"
    if [ -n "${REDIS_TLS_CA_FILE:-}" ]; then echo "tls-ca-cert-file ${REDIS_TLS_CA_FILE}"; fi
    echo "tls-auth-clients ${REDIS_TLS_AUTH_CLIENTS:-no}"
    echo "tls-protocols \"TLSv1.2 TLSv1.3\""
  else
    echo "port ${REDIS_PORT}"
  fi
} >> "$conf"

if [ -n "${REDIS_ACL_FILE:-}" ]; then
  [ -r "$REDIS_ACL_FILE" ] || die "REDIS_ACL_FILE $REDIS_ACL_FILE is not readable"
  echo "aclfile ${REDIS_ACL_FILE}" >> "$conf"
else
  pw=$(secret REDIS_PASSWORD); admin=$(secret REDIS_ADMIN_PASSWORD)
  [ -n "$pw" ] || [ -n "${REDIS_APP_USERS:-}" ] || die "set REDIS_PASSWORD (single site) or REDIS_APP_USERS (shared); refusing to start without authentication"
  {
    if [ -n "$pw" ]; then
      # Single site: one tenant, so SELECT (REDIS_DB) stays allowed.
      echo "user default reset on #$(hash "$pw") ~* &* +@all -@dangerous +info"
    else
      echo "user default off"
    fi
    if [ -n "$admin" ]; then echo "user admin on #$(hash "$admin") ~* &* +@all"; fi
    # Health probe: PING only, no password.
    echo "user health on nopass -@all +ping"
  } >> "$conf"
  for u in $(printf '%s' "${REDIS_APP_USERS:-}" | tr ',' ' '); do
    case "$u" in *[!a-z0-9_-]*|'') die "invalid app user name '$u' (use a-z 0-9 _ -)";; esac
    up=$(printf '%s' "$u" | tr 'a-z-' 'A-Z_')
    p=$(secret "REDIS_APP_PASSWORD_${up}")
    [ -n "$p" ] || die "missing REDIS_APP_PASSWORD_${up} (or _FILE) for app user $u"
    echo "user ${u} reset on #$(hash "$p") ~${u}:* ${APP_CMDS}" >> "$conf"
  done
fi

exec redis-server "$conf"
