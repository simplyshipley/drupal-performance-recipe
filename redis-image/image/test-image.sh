#!/usr/bin/env bash
# Local checks for the Redis image (docs/redis-image.md). Host-side; needs
# Docker. Builds redis:local, then runs three throwaway containers on a
# private network (no host ports): single-site, shared (ACL users), TLS.
# All passwords are SYNTHETIC. Prints PASS/FAIL lines; exit code = FAILs.
#   OUT=<dir> keeps the generated TLS test certificates there (default: a
#   temporary directory).
set -uo pipefail
cd "$(dirname "$0")"
IMG=redis:local NET=redis-test FAILS=0
OUT="${OUT:-$(mktemp -d)}"
# mktemp -d is 0700 and owned by you; on Linux Docker (WSL2) the image's
# non-root valkey user then can't reach the mounted test certificates.
chmod 0755 "$OUT"
say() { printf '%s %s: %s\n' "$1" "$2" "$3"; [[ "$1" == FAIL ]] && FAILS=$((FAILS+1)); return 0; }
cleanup() { for c in prt-single prt-shared prt-tls prt-bad prt-aclfile; do docker container remove -f "$c" >/dev/null 2>&1; done; docker network remove "$NET" >/dev/null 2>&1; }
trap cleanup EXIT
cleanup
docker build -q -t "$IMG" . >/dev/null || { echo "FAIL build"; exit 1; }
docker network create "$NET" >/dev/null
wait_healthy() { local i; for i in $(seq 1 30); do [[ "$(docker inspect -f '{{.State.Health.Status}}' "$1" 2>/dev/null)" == healthy ]] && return 0; sleep 1; done; return 1; }
cli() { local c="$1"; shift; docker exec "$c" redis-cli --no-auth-warning "$@" 2>&1; }

# 1. Refuses to start without authentication.
docker run -d --name prt-bad --network "$NET" "$IMG" >/dev/null; sleep 2
if [[ "$(docker inspect -f '{{.State.Running}}' prt-bad)" == false ]] && docker logs prt-bad 2>&1 | grep -q 'refusing to start without authentication'; then
  say PASS no_auth_refused "container exits: $(docker logs prt-bad 2>&1 | tail -1)"
else say FAIL no_auth_refused "container running without a password"; fi

# 2. Single-site mode.
docker run -d --name prt-single --network "$NET" -e REDIS_PASSWORD=synthetic-single -e REDIS_MAXMEMORY=48mb "$IMG" >/dev/null
if wait_healthy prt-single; then
  u=$(docker exec prt-single id -u); mm=$(cli prt-single -a synthetic-single info memory | tr -d '\r' | sed -n 's/^maxmemory://p'); cg=$(cli prt-single -a synthetic-single config get maxmemory)
  fa=$(cli prt-single -a synthetic-single flushall); ke=$(cli prt-single -a synthetic-single keys '*'); se=$(cli prt-single -a synthetic-single set k v)
  pol=$(docker exec prt-single sh -c 'grep -E "^(maxmemory-policy|save|appendonly|protected-mode)" /run/redis/redis.conf | tr "\n" ";"')
  pwplain=$(docker exec prt-single grep -c synthetic-single /run/redis/redis.conf || true)
  if [[ "$u" != 0 && "$mm" == 50331648 && "$fa" == NOPERM* && "$ke" == NOPERM* && "$cg" == NOPERM* && "$se" == OK && "$pwplain" == 0 ]]; then
    say PASS single_site "uid $u, healthy, maxmemory 48mb, SET ok, FLUSHALL/KEYS/CONFIG denied, password stored only as SHA-256; $pol"
  else say FAIL single_site "uid=$u maxmemory=$mm flushall='$fa' keys='$ke' set='$se' plaintext-password-lines=$pwplain"; fi
else say FAIL single_site "not healthy: $(docker logs prt-single 2>&1 | tail -2)"; fi

# 3. Shared mode: per-site ACL users.
docker run -d --name prt-shared --network "$NET" -e REDIS_APP_USERS=sitea,siteb -e REDIS_APP_PASSWORD_SITEA=synthetic-a \
  -e REDIS_APP_PASSWORD_SITEB=synthetic-b -e REDIS_ADMIN_PASSWORD=synthetic-admin "$IMG" >/dev/null
if wait_healthy prt-shared; then
  A=(--user sitea --pass synthetic-a) B=(--user siteb --pass synthetic-b)
  cli prt-shared "${A[@]}" set sitea:k v >/dev/null; cli prt-shared "${B[@]}" set siteb:k v >/dev/null
  r1=$(cli prt-shared "${A[@]}" get siteb:k); r2=$(cli prt-shared "${A[@]}" del siteb:k); r3=$(cli prt-shared "${A[@]}" flushall)
  r4=$(cli prt-shared "${A[@]}" scan 0); r5=$(cli prt-shared "${A[@]}" select 1); r6=$(cli prt-shared "${A[@]}" config get maxmemory)
  r7=$(cli prt-shared get sitea:k); r8=$(cli prt-shared "${B[@]}" get siteb:k); r9=$(cli prt-shared --user admin --pass synthetic-admin dbsize)
  if [[ "$r1" == NOPERM* && "$r2" == NOPERM* && "$r3" == NOPERM* && "$r4" == NOPERM* && "$r5" == NOPERM* && "$r6" == NOPERM* && "$r7" == NOAUTH* && "$r8" == v && "$r9" == 2 ]]; then
    say PASS shared_acl "sitea: GET/DEL siteb:* denied, FLUSHALL/SCAN/SELECT/CONFIG denied; default user off (NOAUTH); siteb data intact; admin sees 2 keys"
  else say FAIL shared_acl "get='$r1' del='$r2' flushall='$r3' scan='$r4' select='$r5' config='$r6' noauth='$r7' siteb='$r8' dbsize='$r9'"; fi
else say FAIL shared_acl "not healthy: $(docker logs prt-shared 2>&1 | tail -2)"; fi

# 4. TLS with a self-signed CA (generated in a throwaway alpine container).
docker run --name prt-certgen -v "$OUT:/out" alpine:3.20 sh -c 'apk add -q openssl >/dev/null && cd /out &&
  openssl req -x509 -newkey rsa:2048 -nodes -days 2 -subj "/CN=synthetic-ca" -keyout ca.key -out ca.crt 2>/dev/null &&
  openssl req -newkey rsa:2048 -nodes -subj "/CN=prt-tls" -addext "subjectAltName=DNS:prt-tls,DNS:localhost" -keyout redis.key -out redis.csr 2>/dev/null &&
  openssl x509 -req -in redis.csr -CA ca.crt -CAkey ca.key -CAcreateserial -days 2 -copy_extensions copy -out redis.crt 2>/dev/null &&
  chmod 0644 redis.key redis.crt ca.crt' >/dev/null 2>&1
docker container remove prt-certgen >/dev/null 2>&1
if [[ -s "$OUT/redis.crt" ]]; then
  docker run -d --name prt-tls --network "$NET" -v "$OUT:/tls:ro" -e REDIS_PASSWORD=synthetic-tls -e REDIS_TLS=1 \
    -e REDIS_TLS_CERT_FILE=/tls/redis.crt -e REDIS_TLS_KEY_FILE=/tls/redis.key -e REDIS_TLS_CA_FILE=/tls/ca.crt "$IMG" >/dev/null
  if wait_healthy prt-tls; then
    t1=$(cli prt-tls --tls -p 6380 --cacert /tls/ca.crt -a synthetic-tls ping); t2=$(cli prt-tls -p 6380 -a synthetic-tls ping); t3=$(cli prt-tls -p 6379 -a synthetic-tls ping)
    if [[ "$t1" == PONG && "$t2" != PONG && "$t3" != PONG ]]; then say PASS tls "TLS 6380 PONG with the CA; plain text on 6380 refused; 6379 closed"
    else say FAIL tls "tls='$t1' plain-on-6380='$t2' 6379='$t3'"; fi
  else say FAIL tls "not healthy: $(docker logs prt-tls 2>&1 | tail -2)"; fi
else say FAIL tls "could not generate test certificates"; fi

# 5. ACL-file mode: healthcheck must contact redis-server (alive -> 0) and fail
#    when nothing answers (dead -> non-zero). redis-server is PID 1, so a
#    "dead" server is simulated by pointing the check at a closed port.
mkdir -p "$OUT/acl"; printf 'user default off\nuser ops on #%s ~* &* +@all\n' "$(printf 'synthetic-ops' | shasum -a 256 | cut -d' ' -f1)" > "$OUT/acl/users.acl"; chmod 0644 "$OUT/acl/users.acl"
docker run -d --name prt-aclfile --network "$NET" -v "$OUT/acl:/acl:ro" -e REDIS_ACL_FILE=/acl/users.acl "$IMG" >/dev/null
if wait_healthy prt-aclfile; then
  docker exec prt-aclfile /usr/local/bin/redis-healthcheck >/dev/null 2>&1; alive=$?
  docker exec -e REDIS_PORT=6390 prt-aclfile /usr/local/bin/redis-healthcheck >/dev/null 2>&1; dead=$?
  ops=$(cli prt-aclfile --user ops --pass synthetic-ops ping)
  if [[ "$alive" == 0 && "$dead" != 0 && "$ops" == PONG ]]; then say PASS aclfile_health "ACL file loaded (ops PING $ops); healthcheck alive=$alive, with no server on the port=$dead (fails)"
  else say FAIL aclfile_health "alive=$alive dead=$dead ops='$ops'"; fi
else say FAIL aclfile_health "not healthy: $(docker logs prt-aclfile 2>&1 | tail -2)"; fi

echo "# summary: $FAILS failed"
exit "$FAILS"
