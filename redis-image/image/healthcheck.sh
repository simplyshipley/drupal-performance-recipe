#!/bin/sh
# PING as the password-less "health" user (it may run PING only).
if [ "${REDIS_TLS:-0}" = "1" ]; then
  set -- --tls -p "${REDIS_TLS_PORT:-6380}" --cacert "${REDIS_TLS_CA_FILE:-$REDIS_TLS_CERT_FILE}"
  [ -n "${REDIS_TLS_CLIENT_CERT_FILE:-}" ] && set -- "$@" --cert "$REDIS_TLS_CLIENT_CERT_FILE" --key "$REDIS_TLS_CLIENT_KEY_FILE"
else
  set -- -p "${REDIS_PORT:-6379}"
fi
# Always contact redis-server. Generated config: the "health" user may PING.
# ACL-file mode: users are yours, so PING unauthenticated; a NOAUTH/NOPERM
# answer still proves the server is up. Connection errors fail.
if [ -n "${REDIS_ACL_FILE:-}" ]; then
  out=$(redis-cli "$@" ping 2>&1)
else
  out=$(redis-cli "$@" --user health --pass x --no-auth-warning ping 2>&1)
fi
case "$out" in
  PONG|NOAUTH*|NOPERM*|*"Authentication required"*) exit 0 ;;
  *) echo "healthcheck: $out" >&2; exit 1 ;;
esac
