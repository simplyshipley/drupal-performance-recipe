# Cache-only Valkey (Redis-compatible) for Drupal on Azure Container Apps
# (docs/redis-image.md). Pinned official base; runs as the image's non-root
# "valkey" user (uid 999); the config
# is rendered at start from environment variables and secret files.
FROM valkey/valkey:9.1.2-alpine3.24

COPY --chown=root:root --chmod=0755 entrypoint.sh /usr/local/bin/redis-entrypoint
COPY --chown=root:root --chmod=0755 healthcheck.sh /usr/local/bin/redis-healthcheck
COPY --chown=root:root --chmod=0644 redis.conf /etc/redis/redis.base.conf
RUN chmod 0755 /etc/redis && mkdir -p /run/redis && chown valkey:valkey /run/redis && chmod 0700 /run/redis

USER valkey
ENV REDIS_PORT=6379 \
    REDIS_MAXMEMORY=256mb \
    REDIS_MAXMEMORY_POLICY=volatile-lfu \
    REDIS_TLS=0
EXPOSE 6379 6380
HEALTHCHECK --interval=15s --timeout=3s --retries=3 CMD ["/usr/local/bin/redis-healthcheck"]
ENTRYPOINT ["/usr/local/bin/redis-entrypoint"]
