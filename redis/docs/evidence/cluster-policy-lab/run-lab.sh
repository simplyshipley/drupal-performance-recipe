#!/usr/bin/env bash
# Runs the whole lab and writes every output file in this folder.
# Usage: ./run-lab.sh <name suffix> <PHP image with phpredis>
set -euo pipefail
S=${1:?suffix}; PHPIMG=${2:?php image}
V=valkey/valkey:9.1.2-alpine3.24
docker run -d --name "rcl-lab-$S" -v "$PWD:/lab:ro" "$V" sh /lab/start-cluster.sh >/dev/null
sleep 8
{ echo "date: $(date -u +%FT%TZ)"
  echo "valkey image: $V"
  echo "valkey: $(docker exec "rcl-lab-$S" valkey-server --version)"
  echo "php image: $PHPIMG"; } > versions.txt
docker exec "rcl-lab-$S" valkey-cli -p 7000 cluster nodes | awk '{print $2, $3, $9}' > cluster-nodes.txt
: > keyslots.txt
for k in 'site:render:entity_view:node:1' 'site:render:entity_view:node:2' 'site:render:entity_view:node:3' 'site:page:http://x/a' 'site:page:http://x/b'; do
  echo "$k slot $(docker exec "rcl-lab-$S" valkey-cli -p 7000 cluster keyslot "$k")" >> keyslots.txt
done
docker run --name "rcl-php-$S" --network "container:rcl-lab-$S" -v "$PWD:/lab:ro" --entrypoint php "$PHPIMG" /lab/test.php > result.txt 2>&1 || true
docker run --name "rcl-php2-$S" --network "container:rcl-lab-$S" -v "$PWD:/lab:ro" --entrypoint php "$PHPIMG" /lab/test-crossslot.php > result-crossslot.txt 2>&1 || true
{ echo '$ printf "MULTI\nHGETALL site:render:entity_view:node:1\nHGETALL site:render:entity_view:node:2\nEXEC\n" | valkey-cli -p 7001'
  printf 'MULTI\nHGETALL site:render:entity_view:node:1\nHGETALL site:render:entity_view:node:2\nEXEC\n' | docker exec -i "rcl-lab-$S" valkey-cli -p 7001; } > result-crossslot-cli.txt 2>&1
docker stop "rcl-lab-$S" >/dev/null
echo done
