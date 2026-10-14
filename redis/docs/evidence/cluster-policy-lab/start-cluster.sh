#!/bin/sh
# 3 Valkey masters in one container, cluster mode, ports 7000-7002; plus one plain node on 6379 as the control.
set -e
for p in 7000 7001 7002; do
  mkdir -p /data/$p
  valkey-server --port $p --cluster-enabled yes --cluster-config-file /data/$p/nodes.conf --save '' --appendonly no --dir /data/$p --daemonize yes
done
valkey-server --port 6379 --save '' --appendonly no --daemonize yes
sleep 1
valkey-cli --cluster create 127.0.0.1:7000 127.0.0.1:7001 127.0.0.1:7002 --cluster-replicas 0 --cluster-yes
sleep 2
valkey-cli -p 7000 cluster info | grep -E 'cluster_state|cluster_known_nodes'
tail -f /dev/null
