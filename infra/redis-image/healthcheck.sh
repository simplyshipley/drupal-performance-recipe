#!/bin/sh
# PING as the password-less "health" user (it may run PING only).
if [ "${REDIS_TLS:-0}" = "1" ]; then
  set -- --tls -p "${REDIS_TLS_PORT:-6380}" --cacert "${REDIS_TLS_CA_FILE:-$REDIS_TLS_CERT_FILE}"
  [ -n "${REDIS_TLS_CLIENT_CERT_FILE:-}" ] && set -- "$@" --cert "$REDIS_TLS_CLIENT_CERT_FILE" --key "$REDIS_TLS_CLIENT_KEY_FILE"
else
  set -- -p "${REDIS_PORT:-6379}"
fi
[ -n "${REDIS_ACL_FILE:-}" ] && exit 0
out=$(redis-cli "$@" --user health --pass x --no-auth-warning ping 2>&1) || exit 1
[ "$out" = "PONG" ]
